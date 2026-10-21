<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Closure;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Solo\Repository\Filter;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsTo;
use Solo\Repository\Relations\BelongsToMany;
use Solo\Repository\Relations\HasMany;
use Solo\Repository\Relations\HasOne;
use Solo\Repository\Repository;
use Solo\Repository\Translations;
use Solo\Repository\Visibility;
use Stringable;

final class KeylessMappingTest extends TestCase
{
    private Connection $connection;
    private AbstractLogger $logger;
    private Mapping $stores;
    private Mapping $currencies;
    private Repository $stocks;

    protected function setUp(): void
    {
        $this->logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $queries = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $this->queries[] = $context['sql'];
                }
            }
        };
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->logger)]);
        $this->connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            $configuration,
        );
        $statements = [
            'CREATE TABLE stores (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
            'CREATE TABLE currencies (code TEXT PRIMARY KEY COLLATE NOCASE, name TEXT NOT NULL)',
            'CREATE TABLE products (id INTEGER PRIMARY KEY, code TEXT NOT NULL)',
            'CREATE TABLE stocks (product_id INTEGER NOT NULL, store_id INTEGER NOT NULL, '
                . 'quantity INTEGER NOT NULL, currency_code TEXT, deleted_at DATETIME, '
                . 'PRIMARY KEY (product_id, store_id))',
            "INSERT INTO stores VALUES (1, 'North'), (2, 'South')",
            "INSERT INTO currencies VALUES ('EUR', 'Euro'), ('UAH', 'Hryvnia')",
            "INSERT INTO products VALUES (1, 'chair'), (2, 'table'), (3, 'lamp')",
        ];
        foreach ($statements as $sql) {
            $this->connection->executeStatement($sql);
        }

        $this->stores = new Mapping('stores', ['id' => Types::INTEGER, 'name' => Types::STRING], self::object(...));
        $this->currencies = new Mapping(
            'currencies',
            ['code' => Types::STRING, 'name' => Types::STRING],
            self::object(...),
            primaryKey: 'code',
            generatedId: false,
        );
        $this->stocks = new Repository($this->connection, $this->stockMapping());
        $this->stocks->insertMany([
            ['product_id' => 1, 'store_id' => 1, 'quantity' => 5, 'currency_code' => 'eur'],
            ['product_id' => 1, 'store_id' => 2, 'quantity' => 2, 'currency_code' => 'UAH'],
            ['product_id' => 2, 'store_id' => 1, 'quantity' => 7, 'currency_code' => null],
            ['product_id' => 3, 'store_id' => 3, 'quantity' => 1, 'currency_code' => 'EUR'],
        ]);
        $this->logger->queries = [];
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testRowsAreReadInsertedAndChangedByCriteria(): void
    {
        $mapping = $this->stockMapping();
        self::assertNull($mapping->primaryKey);
        self::assertFalse($mapping->generatedId);

        self::assertSame(1, $this->stocks->insert(['product_id' => 2, 'store_id' => 2, 'quantity' => 4]));
        $read = $this->stocks->read();
        self::assertSame(
            [[1, 1, 5], [1, 2, 2], [2, 1, 7], [2, 2, 4], [3, 3, 1]],
            array_map(
                static fn (object $stock): array => [$stock->product_id, $stock->store_id, $stock->quantity],
                $read->findBy(orderBy: ['product_id' => 'ASC', 'store_id' => 'ASC']),
            ),
        );
        self::assertSame(2, $read->count(['product_id' => 1]));
        self::assertSame(19, $read->sum('quantity'));

        self::assertSame(1, $this->stocks->updateBy(['product_id' => 1, 'store_id' => 2], ['quantity' => 9]));
        self::assertSame([['quantity' => 9]], $read->rows(['quantity'], ['product_id' => 1, 'store_id' => 2]));
        self::assertSame(3, (int) $read->queryBuilder('s', ['quantity >=' => 5])->select('COUNT(*)')->fetchOne());

        self::assertSame(1, $this->stocks->deleteBy(['product_id' => 3, 'store_id' => 3]));
        self::assertSame(4, $read->count());
        self::assertSame(1, $read->withVisibility(Visibility::Deleted)->count());
        self::assertSame(1, $this->stocks->purgeBy(['product_id' => 3]));
        self::assertSame(4, $read->withVisibility(Visibility::All)->count());
    }

    #[DataProvider('operationsById')]
    public function testOperationsByIdRequireAPrimaryKeyBeforeSql(Closure $operation, string $name): void
    {
        try {
            $operation($this->stocks);
            self::fail('An operation by ID must be rejected without a primary key.');
        } catch (LogicException $exception) {
            self::assertSame($name . ' requires a primary key; mapping "stocks" has none.', $exception->getMessage());
            self::assertSame([], $this->logger->queries);
        }
    }

    public static function operationsById(): iterable
    {
        yield 'find' => [static fn (Repository $stocks): mixed => $stocks->read()->find(1), 'find()'];
        yield 'update' => [static fn (Repository $stocks): mixed => $stocks->update(1, ['quantity' => 0]), 'update()'];
        yield 'delete' => [static fn (Repository $stocks): mixed => $stocks->delete(1), 'delete()'];
        yield 'restore' => [static fn (Repository $stocks): mixed => $stocks->restore(1), 'restore()'];
        yield 'purge' => [static fn (Repository $stocks): mixed => $stocks->purge(1), 'purge()'];
        yield 'insert returning an ID' => [
            static fn (Repository $stocks): mixed => $stocks->insertGetId(['product_id' => 9, 'store_id' => 9]),
            'insertGetId()',
        ];
        yield 'row locks' => [static fn (Repository $stocks): mixed => $stocks->lockForUpdate([1]), 'lockForUpdate()'];
    }

    public function testKeysetIterationAndRowsOfOnlyDefaultsAreRejectedBeforeSql(): void
    {
        $operations = [
            'Keyset iteration requires an integer primary key.' => static fn (Repository $stocks): mixed
                => $stocks->read()->iterateById(),
            'Rows of a mapping without a primary key need at least one column.' => static fn (Repository $stocks): mixed
                => $stocks->insertMany([[]]),
        ];
        foreach ($operations as $message => $operation) {
            try {
                $operation($this->stocks);
                self::fail('Expected the operation to be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
        self::assertSame([], $this->logger->queries);
    }

    #[DataProvider('definitionsNeedingAnOwnerKey')]
    public function testOwnersWithoutAPrimaryKeyRejectTranslationsAndRelationsByThatKey(
        array $definition,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Mapping(...$definition + [
            'table' => 'stocks',
            'columns' => ['product_id' => Types::INTEGER, 'store_id' => Types::INTEGER],
            'hydrate' => self::object(...),
            'primaryKey' => null,
        ]);
    }

    public static function definitionsNeedingAnOwnerKey(): iterable
    {
        $target = static fn (): Mapping => new Mapping('stores', ['id' => Types::INTEGER], self::object(...));
        yield 'translations' => [
            ['translations' => new Translations('stock_translations', 'stock_id', ['label' => Types::STRING])],
            'Translations require a primary key.',
        ];
        yield 'has one' => [
            ['relations' => ['store' => new HasOne($target, 'stock_id')]],
            'Relation "store" requires a primary key on its owner.',
        ];
        yield 'has many' => [
            ['relations' => ['stores' => new HasMany($target, 'stock_id')]],
            'Relation "stores" requires a primary key on its owner.',
        ];
        yield 'many to many' => [
            ['relations' => ['stores' => new BelongsToMany($target, 'stock_stores', 'stock_id', 'store_id')]],
            'Relation "stores" requires a primary key on its owner.',
        ];
    }

    public function testBelongsToTargetsRequireAPrimaryKeyBeforeSql(): void
    {
        $products = new Repository($this->connection, new Mapping(
            'products',
            ['id' => Types::INTEGER, 'code' => Types::STRING],
            self::object(...),
            relations: ['stock' => new BelongsTo(fn (): Mapping => $this->stockMapping(), 'id', self::assign('stock'))],
        ));

        $reads = [
            static fn (): mixed => $products->with(['stock'])->findBy(),
            static fn (): mixed => $products->read()->count(Filter::related('stock')),
        ];
        foreach ($reads as $read) {
            try {
                $read();
                self::fail('A BelongsTo target without a primary key must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('BelongsTo and BelongsToMany targets require a primary key.', $exception->getMessage());
            }
        }
        self::assertSame([], $this->logger->queries);
    }

    public function testOwnersWithoutAPrimaryKeyLoadBelongsToByIntegerKeys(): void
    {
        $stocks = $this->stocks->with(['store'])->findBy(orderBy: ['product_id' => 'ASC', 'store_id' => 'ASC']);

        self::assertSame(
            ['North', 'South', 'North', null],
            array_map(static fn (object $stock): ?string => $stock->store?->name, $stocks),
        );
        self::assertCount(2, $this->logger->queries);
    }

    public function testOwnersWithoutAPrimaryKeyFilterByStringKeysButCannotLoadThem(): void
    {
        $rows = $this->stocks->read()->rows(
            ['product_id', 'store_id'],
            Filter::related('currency', ['name' => 'Euro']),
            ['product_id' => 'ASC'],
        );
        self::assertSame([['product_id' => 1, 'store_id' => 1], ['product_id' => 3, 'store_id' => 3]], $rows);

        $this->logger->queries = [];
        try {
            $this->stocks->with(['currency'])->findBy();
            self::fail('Loading by string keys must be rejected without an owner primary key.');
        } catch (LogicException $exception) {
            self::assertSame(
                'Loading relation "currency" by string keys requires a primary key; mapping "stocks" has none.',
                $exception->getMessage(),
            );
        }
        self::assertSame([], $this->logger->queries);
    }

    public function testKeyedOwnersLoadAndFilterTargetsWithoutAPrimaryKey(): void
    {
        $products = new Repository($this->connection, new Mapping(
            'products',
            ['id' => Types::INTEGER, 'code' => Types::STRING],
            self::object(...),
            relations: ['stocks' => new HasMany(
                fn (): Mapping => $this->stockMapping(),
                'product_id',
                self::assign('stocks'),
                ['store_id' => 'ASC'],
            )],
        ));
        $loaded = $products->with(['stocks.store'])->findBy(orderBy: ['id' => 'ASC']);
        self::assertSame(
            [['North', 'South'], ['North'], [null]],
            array_map(
                static fn (object $product): array => array_map(
                    static fn (object $stock): ?string => $stock->store?->name,
                    $product->stocks,
                ),
                $loaded,
            ),
        );
        self::assertSame(
            [1, 2],
            array_column($products->read()->rows(['id'], Filter::related('stocks', ['quantity >=' => 5])), 'id'),
        );

        // String keys join their owners in SQL; every target row is kept although none has a key.
        $currencies = new Repository($this->connection, new Mapping(
            'currencies',
            ['code' => Types::STRING, 'name' => Types::STRING],
            self::object(...),
            primaryKey: 'code',
            generatedId: false,
            relations: ['stocks' => new HasMany(
                fn (): Mapping => $this->stockMapping(),
                'currency_code',
                self::assign('stocks'),
                ['product_id' => 'ASC'],
            )],
        ));
        $byCurrency = [];
        foreach ($currencies->with(['stocks'])->findBy(orderBy: ['code' => 'ASC']) as $currency) {
            $byCurrency[$currency->code] = array_map(
                static fn (object $stock): array => [$stock->product_id, $stock->store_id],
                $currency->stocks,
            );
        }
        self::assertSame(['EUR' => [[1, 1], [3, 3]], 'UAH' => [[1, 2]]], $byCurrency);
    }

    private function stockMapping(): Mapping
    {
        return new Mapping(
            table: 'stocks',
            columns: [
                'product_id' => Types::INTEGER,
                'store_id' => Types::INTEGER,
                'quantity' => Types::INTEGER,
                'currency_code' => Types::STRING,
                'deleted_at' => Types::DATETIME_IMMUTABLE,
            ],
            hydrate: self::object(...),
            primaryKey: null,
            softDeleteColumn: 'deleted_at',
            relations: [
                'store' => new BelongsTo(fn (): Mapping => $this->stores, 'store_id', self::assign('store')),
                'currency' => new BelongsTo(
                    fn (): Mapping => $this->currencies,
                    'currency_code',
                    self::assign('currency'),
                ),
            ],
        );
    }

    private static function object(array $row): object
    {
        return (object) $row;
    }

    private static function assign(string $property): Closure
    {
        return static function (object $owner, mixed $value) use ($property): object {
            $owner->{$property} = $value;

            return $owner;
        };
    }
}
