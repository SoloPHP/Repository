<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Repository\Load;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsTo;
use Solo\Repository\Relations\HasMany;
use Solo\Repository\Repository;
use Solo\Repository\Tests\Fixtures\QueryLog;
use Solo\Repository\Translations;
use Solo\Repository\Visibility;

final class RelationOrderTest extends TestCase
{
    private Connection $connection;
    private QueryLog $log;
    private Repository $products;

    protected function setUp(): void
    {
        $this->log = new QueryLog();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->log)]);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        foreach (
            [
                'CREATE TABLE brands (id INTEGER PRIMARY KEY, name TEXT NOT NULL, deleted_at DATETIME)',
                'CREATE TABLE brand_translations (brand_id INTEGER NOT NULL, locale TEXT NOT NULL, title TEXT, '
                    . 'PRIMARY KEY (brand_id, locale))',
                'CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT NOT NULL, brand_id INTEGER)',
                'CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT NOT NULL, category_id INTEGER, '
                    . 'brand_id INTEGER)',
                'CREATE TABLE variants (id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, sku TEXT NOT NULL)',
            ] as $sql
        ) {
            $this->connection->executeStatement($sql);
        }
        $this->seed('brands', ['id', 'name', 'deleted_at'], [
            [1, 'Acme', null], [2, 'Zeta', null], [3, 'Bolt', '2026-01-01 00:00:00'],
        ]);
        $this->seed('brand_translations', ['brand_id', 'locale', 'title'], [
            [1, 'uk', 'Yankee UK'], [1, 'en', 'Alpha EN'], [2, 'en', 'Bravo EN'], [3, 'en', 'Aardvark EN'],
        ]);
        $this->seed('categories', ['id', 'name', 'brand_id'], [[10, 'Tables', 2], [20, 'Chairs', 1], [30, 'Lamps', null]]);
        $this->seed('products', ['id', 'sku', 'category_id', 'brand_id'], [
            [1, 'p1', 10, 1], [2, 'p2', 20, 2], [3, 'p3', null, null], [4, 'p4', 30, 3], [5, 'p5', 20, 1],
        ]);
        $this->products = new Repository($this->connection, $this->productMapping());
        $this->log->reset();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testOrdersByAFieldOfABelongsToTarget(): void
    {
        $read = $this->products->read();

        // SQLite orders NULL first; a product without a category orders as one with a NULL name.
        self::assertSame([3, 2, 5, 4, 1], self::ids($read->findBy([], ['category.name' => 'ASC', 'id' => 'ASC'])));
        self::assertSame([1, 4, 2, 5, 3], self::ids($read->findBy([], ['category.name' => 'DESC', 'id' => 'ASC'])));
        self::assertStringContainsString(
            'LEFT JOIN "categories" br_base_order1 ON "br_base_order1"."id" = "br_base"."category_id"'
            . ' WHERE 1 = 1 ORDER BY "br_base_order1"."name" ASC, "br_base"."id" ASC',
            $this->log->statements()[0],
        );
    }

    public function testFieldsOfOnePathShareOneJoinAndPathsNest(): void
    {
        $rows = $this->products->read()->rows(
            ['id'],
            ['sku !=' => 'none'],
            ['category.brand.name' => 'ASC', 'category.name' => 'DESC', 'brand.name' => 'ASC', 'id' => 'ASC'],
        );

        // p4's category has no brand and p3 has no category; NULL orders last when descending.
        self::assertSame([4, 3, 5, 2, 1], array_column($rows, 'id'));
        $sql = $this->log->statements()[0];
        self::assertSame(1, substr_count($sql, 'JOIN "categories"'));
        self::assertSame(2, substr_count($sql, 'JOIN "brands"'));
        self::assertStringContainsString(
            'LEFT JOIN "brands" br_base_order2 ON "br_base_order2"."id" = "br_base_order1"."brand_id"',
            $sql,
        );
        self::assertStringContainsString(
            'LEFT JOIN "brands" br_base_order3 ON "br_base_order3"."id" = "br_base"."brand_id"',
            $sql,
        );
        self::assertStringContainsString(
            'ORDER BY "br_base_order2"."name" ASC, "br_base_order1"."name" DESC, "br_base_order3"."name" ASC',
            $sql,
        );
    }

    public function testSoftDeletedTargetsOrderAsMissingOnes(): void
    {
        // Bolt is deleted: p4 orders with p3, which has no brand, instead of between Acme and Zeta.
        self::assertSame(
            [3, 4, 1, 5, 2],
            self::ids($this->products->read()->findBy([], ['brand.name' => 'ASC', 'id' => 'ASC'])),
        );
        self::assertStringContainsString(
            'ON "br_base_order1"."id" = "br_base"."brand_id" AND "br_base_order1"."deleted_at" IS NULL',
            $this->log->statements()[0],
        );
    }

    public function testTranslatedTargetFieldsFollowTheLocaleOfTheRead(): void
    {
        $order = ['brand.title' => 'ASC', 'id' => 'ASC'];

        self::assertSame([3, 4, 2, 1, 5], self::ids($this->products->withLocale('uk', 'en')->findBy([], $order)));
        self::assertSame([3, 4, 1, 5, 2], self::ids($this->products->withLocale('en')->findBy([], $order)));
        self::assertStringContainsString(
            'COALESCE("br_base_order1_translation"."title", "br_base_order1_fallback"."title") ASC',
            $this->log->statements()[0],
        );

        $this->log->reset();
        try {
            $this->products->read()->findBy([], $order);
            self::fail('A translated order field needs a locale.');
        } catch (LogicException $exception) {
            self::assertStringStartsWith('Translated field "title" needs a locale', $exception->getMessage());
            self::assertSame([], $this->log->statements());
        }
    }

    public function testOrderPathsSeeTheTargetRowsTheirLoadSees(): void
    {
        $order = ['brand.name' => 'ASC', 'id' => 'ASC'];

        // Bolt is deleted: a load of every brand shows it, so p4 orders between Acme and Zeta.
        self::assertSame([3, 4, 1, 5, 2], self::ids($this->products->with(['brand'])->findBy([], $order)));
        self::assertSame([3, 1, 5, 4, 2], self::ids(
            $this->products->with([new Load('brand', visibility: Visibility::All)])->findBy([], $order),
        ));
        self::assertSame([1, 2, 3, 5, 4], self::ids(
            $this->products->with([new Load('brand', visibility: Visibility::Deleted)])->findBy([], $order),
        ));
        [$active, $all, $deleted] = array_values(array_filter(
            $this->log->statements(),
            static fn(string $sql): bool => str_contains($sql, ' FROM "products" '),
        ));
        self::assertStringContainsString('"br_base_order1"."deleted_at" IS NULL', $active);
        self::assertStringNotContainsString('"br_base_order1"."deleted_at"', $all);
        self::assertStringContainsString('"br_base_order1"."deleted_at" IS NOT NULL', $deleted);

        // A nested load sets the visibility of its own segment; the direct path keeps the default.
        $this->log->reset();
        $this->products->with([new Load('category.brand', visibility: Visibility::All)])
            ->findBy([], ['category.brand.name' => 'ASC', 'brand.name' => 'ASC']);
        $sql = $this->log->statements()[0];
        self::assertStringNotContainsString('"br_base_order2"."deleted_at"', $sql);
        self::assertStringContainsString('"br_base_order3"."deleted_at" IS NULL', $sql);

        // The default order of a collection follows the loads below it the same way.
        $this->log->reset();
        $this->categoryRepository()->with(['products', new Load('products.brand', visibility: Visibility::All)])
            ->find(20);
        self::assertStringNotContainsString('"br_base_order1"."deleted_at"', $this->log->statements()[1]);
    }

    public function testOrderJoinsRejectAVisibilityTheirTargetCannotHave(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deleted visibility requires a soft-delete column.');
        try {
            $this->products->with([new Load('category', visibility: Visibility::Deleted)])
                ->findBy([], ['category.name' => 'ASC']);
        } finally {
            self::assertSame([], $this->log->statements());
        }
    }

    public function testPathOrderCombinesWithCriteriaPagesAndRelationLoads(): void
    {
        $page = $this->products->with(['category'])->findBy(
            ['sku IN' => ['p1', 'p2', 'p4', 'p5']],
            ['category.name' => 'ASC', 'id' => 'DESC'],
            2,
            1,
        );

        self::assertSame([2, 4], self::ids($page));
        self::assertSame(['Chairs', 'Lamps'], array_map(static fn(object $product): string => $product->category->name, $page));
        self::assertSame(2, $this->products->read()->findOneBy([], ['brand.name' => 'DESC', 'id' => 'ASC'])?->id);
    }

    public function testCollectionOrdersFollowPathsOfTheirTarget(): void
    {
        $categories = $this->categoryRepository();

        self::assertSame([5, 2], self::ids($categories->with(['products'])->find(20)?->products ?? []));
        self::assertSame([2, 5], self::ids($categories->with([
            new Load('products', orderBy: ['brand.name' => 'DESC', 'id' => 'ASC']),
        ])->find(20)?->products ?? []));

        $this->log->reset();
        try {
            $categories->with([new Load('products', orderBy: ['maker.name' => 'ASC'])])->find(20);
            self::fail('An unknown relation in a collection order is rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Unknown mapped relation "maker".', $exception->getMessage());
            self::assertSame([], $this->log->statements());
        }
    }

    #[DataProvider('invalidOrders')]
    public function testOrderPathsAreValidatedBeforeSql(array $orderBy, string $message): void
    {
        try {
            $this->products->read()->findBy([], $orderBy);
            self::fail('Expected the order to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertSame([], $this->log->statements());
        }
    }

    public static function invalidOrders(): iterable
    {
        $syntax = 'Order must use field names or relation paths to fields.';

        yield 'unknown relation' => [['maker.name' => 'ASC'], 'Unknown mapped relation "maker".'];
        yield 'collection relation' => [
            ['variants.sku' => 'ASC'],
            'An order path follows BelongsTo relations only; "variants" is not one.',
        ];
        yield 'unknown target field' => [['category.missing' => 'ASC'], 'Unknown mapped field "missing".'];
        yield 'relation without a field' => [['category' => 'ASC'], 'Unknown mapped field "category".'];
        yield 'empty segment' => [['category..name' => 'ASC'], $syntax];
        yield 'trailing dot' => [['category.' => 'ASC'], $syntax];
        yield 'quoted segment' => [['category."name"' => 'ASC'], $syntax];
        yield 'direction' => [['category.name' => 'ASC NULLS LAST'], 'Order direction must be ASC or DESC.'];
    }

    private static function assign(string $name): \Closure
    {
        return static function (object $owner, ?object $target) use ($name): object {
            $owner->{$name} = $target;

            return $owner;
        };
    }

    /** @param list<object> $entities */
    private static function ids(array $entities): array
    {
        return array_map(static fn(object $entity): int => $entity->id, $entities);
    }

    /**
     * @param list<string> $columns
     * @param list<list<mixed>> $rows
     */
    private function seed(string $table, array $columns, array $rows): void
    {
        foreach ($rows as $row) {
            $this->connection->insert($table, array_combine($columns, $row));
        }
    }

    /** Categories whose products are ordered by the name of their brand unless a load says otherwise. */
    private function categoryRepository(): Repository
    {
        $model = static fn(array $row): object => (object) $row;
        $brands = new Mapping(
            table: 'brands',
            columns: ['id' => Types::INTEGER, 'name' => Types::STRING, 'deleted_at' => Types::DATETIME_IMMUTABLE],
            hydrate: $model,
            softDeleteColumn: 'deleted_at',
        );
        $products = new Mapping(
            table: 'products',
            columns: [
                'id' => Types::INTEGER,
                'sku' => Types::STRING,
                'category_id' => Types::INTEGER,
                'brand_id' => Types::INTEGER,
            ],
            hydrate: $model,
            relations: ['brand' => new BelongsTo(static fn(): Mapping => $brands, 'brand_id', self::assign('brand'))],
        );

        return new Repository($this->connection, new Mapping(
            table: 'categories',
            columns: ['id' => Types::INTEGER, 'name' => Types::STRING, 'brand_id' => Types::INTEGER],
            hydrate: $model,
            relations: [
                'products' => new HasMany(
                    static fn(): Mapping => $products,
                    'category_id',
                    static function (object $category, array $products): object {
                        $category->products = $products;

                        return $category;
                    },
                    ['brand.name' => 'ASC', 'id' => 'ASC'],
                ),
            ],
        ));
    }

    private function productMapping(): Mapping
    {
        $model = static fn(array $row): object => (object) $row;
        $brands = new Mapping(
            table: 'brands',
            columns: ['id' => Types::INTEGER, 'name' => Types::STRING, 'deleted_at' => Types::DATETIME_IMMUTABLE],
            hydrate: $model,
            softDeleteColumn: 'deleted_at',
            translations: new Translations('brand_translations', 'brand_id', ['title' => Types::STRING]),
        );
        $categories = new Mapping(
            table: 'categories',
            columns: ['id' => Types::INTEGER, 'name' => Types::STRING, 'brand_id' => Types::INTEGER],
            hydrate: $model,
            relations: ['brand' => new BelongsTo(static fn(): Mapping => $brands, 'brand_id', self::assign('brand'))],
        );
        $variants = new Mapping(
            table: 'variants',
            columns: ['id' => Types::INTEGER, 'product_id' => Types::INTEGER, 'sku' => Types::STRING],
            hydrate: $model,
        );

        return new Mapping(
            table: 'products',
            columns: [
                'id' => Types::INTEGER,
                'sku' => Types::STRING,
                'category_id' => Types::INTEGER,
                'brand_id' => Types::INTEGER,
            ],
            hydrate: $model,
            relations: [
                'category' => new BelongsTo(
                    static fn(): Mapping => $categories,
                    'category_id',
                    static function (object $product, ?object $category): object {
                        $product->category = $category;

                        return $product;
                    },
                ),
                'brand' => new BelongsTo(static fn(): Mapping => $brands, 'brand_id', self::assign('brand')),
                'variants' => new HasMany(static fn(): Mapping => $variants, 'product_id'),
            ],
        );
    }
}
