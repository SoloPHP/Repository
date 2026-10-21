<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Closure;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Types\Types;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Solo\Repository\Filter;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\HasMany;
use Solo\Repository\Repository;
use Stringable;

/** Mappings without a hydrator: tables read as rows, aggregates and SQL, never as entities. */
final class RowsOnlyMappingTest extends TestCase
{
    private Connection $connection;
    private AbstractLogger $logger;
    private Repository $links;
    private Repository $products;

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
            'CREATE TABLE products (id INTEGER PRIMARY KEY, code TEXT NOT NULL)',
            'CREATE TABLE product_categories (product_id INTEGER NOT NULL, category_id INTEGER NOT NULL, '
                . 'is_primary BOOLEAN NOT NULL, PRIMARY KEY (product_id, category_id))',
            "INSERT INTO products VALUES (1, 'chair'), (2, 'table'), (3, 'lamp')",
        ];
        foreach ($statements as $sql) {
            $this->connection->executeStatement($sql);
        }

        $this->links = new Repository($this->connection, self::linkMapping());
        $this->links->insertMany([
            ['product_id' => 1, 'category_id' => 10, 'is_primary' => true],
            ['product_id' => 1, 'category_id' => 20, 'is_primary' => false],
            ['product_id' => 2, 'category_id' => 20, 'is_primary' => true],
        ]);
        $this->products = new Repository($this->connection, new Mapping(
            'products',
            ['id' => Types::INTEGER, 'code' => Types::STRING],
            static fn (array $row): object => (object) $row,
            relations: ['categories' => new HasMany(
                self::linkMapping(...),
                'product_id',
                static function (object $product, array $links): object {
                    $product->categories = $links;

                    return $product;
                },
            )],
        ));
        $this->logger->queries = [];
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testRowsAggregatesQueryBuildersAndWritesNeedNoHydrator(): void
    {
        self::assertNull(self::linkMapping()->hydrate);

        $read = $this->links->read();
        self::assertSame(
            [['category_id' => 10, 'is_primary' => true], ['category_id' => 20, 'is_primary' => false]],
            $read->rows(['category_id', 'is_primary'], ['product_id IN' => [1, 3]], ['category_id' => 'ASC']),
        );
        self::assertSame(2, $read->count(['category_id' => 20]));
        self::assertTrue($read->exists(['product_id' => 2]));
        self::assertSame(50, $read->sum('category_id'));
        self::assertSame(
            [1, 2],
            array_map('intval', $read->queryBuilder('pc', ['category_id' => 20])
                ->select('pc.product_id')
                ->orderBy('pc.product_id')
                ->fetchFirstColumn()),
        );

        self::assertSame(1, $this->links->updateBy(['product_id' => 1, 'category_id' => 20], ['is_primary' => true]));
        self::assertSame(1, $this->links->deleteBy(['product_id' => 2]));
        self::assertSame(
            [['product_id' => 1, 'category_id' => 10], ['product_id' => 1, 'category_id' => 20]],
            $read->rows(['product_id', 'category_id'], ['is_primary' => true], ['category_id' => 'ASC']),
        );
    }

    public function testOwnersFilterByARelationWhoseTargetHasNoHydrator(): void
    {
        $products = $this->products->read();

        self::assertSame([['id' => 1]], $products->rows(['id'], Filter::related('categories', ['category_id' => 10])));
        self::assertSame(
            ['chair', 'table'],
            array_map(
                static fn (object $product): string => $product->code,
                $products->findBy(Filter::related('categories'), ['id' => 'ASC']),
            ),
        );
    }

    #[DataProvider('entityReads')]
    public function testEntityReadsRequireAHydratorBeforeSql(Closure $read, string $table, string $operation): void
    {
        try {
            $read($this->links, $this->products, $this->connection);
            self::fail('Reading entities must be rejected without a hydrator.');
        } catch (LogicException $exception) {
            self::assertSame(
                sprintf('%s requires a hydrator; mapping "%s" has none.', $operation, $table),
                $exception->getMessage(),
            );
            self::assertSame([], $this->logger->queries);
        }
    }

    public static function entityReads(): iterable
    {
        $links = 'product_categories';
        yield 'findBy' => [static fn (Repository $rows): mixed => $rows->read()->findBy(), $links, 'Reading entities'];
        yield 'findOneBy' => [
            static fn (Repository $rows): mixed => $rows->read()->findOneBy(['product_id' => 1]),
            $links,
            'Reading entities',
        ];
        yield 'an empty page' => [
            static fn (Repository $rows): mixed => $rows->read()->findBy(limit: 0),
            $links,
            'Reading entities',
        ];
        yield 'keyset iteration' => [
            static fn (Repository $rows): mixed => $rows->read()->iterateById(),
            $links,
            'Reading entities',
        ];
        yield 'find by primary key' => [
            static fn (Repository $rows, Repository $products, Connection $connection): mixed => (new Repository(
                $connection,
                new Mapping('products', ['id' => Types::INTEGER, 'code' => Types::STRING]),
            ))->read()->find(1),
            'products',
            'Reading entities',
        ];
        yield 'loading a relation' => [
            static fn (Repository $rows, Repository $products): mixed => $products->with(['categories'])->findBy(),
            $links,
            'Loading relation "categories"',
        ];
    }

    /** @return Mapping<object> */
    private static function linkMapping(): Mapping
    {
        return new Mapping(
            table: 'product_categories',
            columns: [
                'product_id' => Types::INTEGER,
                'category_id' => Types::INTEGER,
                'is_primary' => Types::BOOLEAN,
            ],
            primaryKey: null,
        );
    }
}
