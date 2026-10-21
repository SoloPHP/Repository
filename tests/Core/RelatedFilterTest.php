<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Solo\Repository\Filter;
use Solo\Repository\Internal\Database;
use Solo\Repository\Internal\FieldResolver;
use Solo\Repository\Internal\Locale;
use Solo\Repository\Internal\PredicateCompiler;
use Solo\Repository\Mapping;
use Solo\Repository\ReadQuery;
use Solo\Repository\Relations\BelongsTo;
use Solo\Repository\Relations\BelongsToMany;
use Solo\Repository\Relations\HasMany;
use Solo\Repository\Relations\HasOne;
use Solo\Repository\Repository;
use Solo\Repository\Translations;
use Solo\Repository\Visibility;
use Stringable;

final class RelatedFilterTest extends TestCase
{
    private Connection $connection;
    private Repository $products;
    private Mapping $categories;
    private Mapping $tags;
    private AbstractLogger $log;

    protected function setUp(): void
    {
        $this->log = new class extends AbstractLogger {
            public array $sql = [];
            public array $parameters = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $this->sql[] = $context['sql'];
                    $this->parameters[] = $context['params'] ?? [];
                }
            }
        };
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->log)]);
        $this->connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            $configuration,
        );
        $this->createTables();
        $this->seed();
        $this->categories = new Mapping(
            table: 'categories',
            columns: [
                'id' => Types::INTEGER,
                'parent_id' => Types::INTEGER,
                'code' => Types::STRING,
                'deleted_at' => Types::DATETIME_IMMUTABLE,
            ],
            hydrate: self::forbiddenHydration(...),
            softDeleteColumn: 'deleted_at',
            translations: new Translations('category_translations', 'category_id', ['name' => Types::STRING], 'de'),
            relations: [
                'parent' => new BelongsTo(
                    fn (): Mapping => $this->categories,
                    'parent_id',
                    self::forbiddenAssign(...),
                ),
            ],
        );
        $this->tags = new Mapping(
            table: 'tags',
            columns: [
                'id' => Types::INTEGER,
                'category_id' => Types::INTEGER,
                'slug' => Types::STRING,
                'color' => Types::STRING,
                'size' => Types::STRING,
                'enabled' => Types::BOOLEAN,
                'deleted_at' => Types::DATETIME_IMMUTABLE,
            ],
            hydrate: self::forbiddenHydration(...),
            softDeleteColumn: 'deleted_at',
            translations: new Translations('tag_translations', 'tag_id', ['name' => Types::STRING], 'uk'),
            relations: [
                'category' => new BelongsTo(
                    fn (): Mapping => $this->categories,
                    'category_id',
                    self::forbiddenAssign(...),
                ),
            ],
        );
        $this->products = $this->productRepository();
        $this->clearLog();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[DataProvider('relationCases')]
    public function testAllRelationKindsUseTheirOwnTargetScope(Filter $criteria, array $ids): void
    {
        self::assertSame($ids, $this->ids($this->products->read(), $criteria));
        self::assertCount(1, $this->log->sql);
        self::assertStringContainsString('EXISTS (SELECT 1', $this->log->sql[0]);
    }

    public static function relationCases(): iterable
    {
        yield 'belongs to' => [Filter::related('category', ['code' => 'chairs']), [2, 6]];
        yield 'has one' => [Filter::related('detail', ['note' => 'good']), [1]];
        yield 'has many' => [Filter::related('reviews', ['rating >=' => 4]), [1, 2]];
        yield 'many to many' => [Filter::related('tags', ['enabled' => true]), [1, 2]];
        yield 'exists without conditions' => [Filter::related('tags'), [1, 2]];
        yield 'not exists' => [Filter::not(Filter::related('tags')), [3, 4, 6]];
    }

    public function testOneRelatedCallRequiresTheSameRowButSeparateCallsMayUseDifferentRows(): void
    {
        self::assertSame([2], $this->ids($this->products->read(), Filter::related('tags', [
            'color' => 'red',
            'size' => 'large',
        ])));
        self::assertSame([1, 2], $this->ids($this->products->read(), Filter::all(
            Filter::related('tags', ['color' => 'red']),
            Filter::related('tags', ['size' => 'large']),
        )));
    }

    public function testOrInsideAndOutsideRelatedPreservesGroupingAndVisibility(): void
    {
        self::assertSame([1, 2], $this->ids($this->products->read(), Filter::related('tags', Filter::any(
            ['slug' => 'red-small'],
            ['slug' => 'red-large'],
        ))));
        $criteria = Filter::any(['id' => 4], Filter::related('tags', ['slug' => 'red-large']));
        self::assertSame([2, 4], $this->ids($this->products->read(), $criteria));
        self::assertSame([2, 4, 5], $this->ids(
            $this->products->read()->withVisibility(Visibility::All),
            $criteria,
        ));
    }

    public function testMultipleMatchingRelatedRowsDoNotDuplicateParentsOrChangePagination(): void
    {
        $criteria = Filter::related('tags', ['color' => 'red', 'size' => 'large']);
        self::assertSame(1, $this->products->read()->count($criteria));
        self::assertTrue($this->products->read()->exists($criteria));
        self::assertSame([['id' => 2]], $this->products->read()->rows(['id'], $criteria, ['id' => 'ASC'], 1));
        self::assertSame([], $this->products->read()->rows(['id'], $criteria, ['id' => 'ASC'], 1, 1));
    }

    public function testRelationVisibilityIsExplicitAndCannotBeOverriddenByAUserOrBranch(): void
    {
        self::assertSame([], $this->ids($this->products->read(), Filter::related('tags', Filter::any(
            ['slug' => 'not-present'],
            ['deleted_at !=' => null],
        ))));
        self::assertSame([3, 6], $this->ids($this->products->read(), Filter::related(
            'tags',
            visibility: Visibility::Deleted,
        )));
        self::assertSame([1, 2, 3, 6], $this->ids($this->products->read(), Filter::related(
            'tags',
            visibility: Visibility::All,
        )));
        self::assertSame([1, 2, 5], $this->ids(
            $this->products->read()->withVisibility(Visibility::All),
            Filter::related('tags'),
        ));
        self::assertSame([], $this->ids($this->products->read(), Filter::related(
            'tags',
            Filter::related('category', ['code' => 'hidden']),
            Visibility::All,
        )));
        self::assertSame([3, 6], $this->ids($this->products->read(), Filter::related(
            'tags',
            Filter::related('category', ['code' => 'hidden'], Visibility::All),
            Visibility::All,
        )));
    }

    public function testNestedAndSelfRelationsCorrelateToTheImmediateOwner(): void
    {
        $categories = new Repository($this->connection, $this->categories);
        self::assertSame([2], $this->ids($categories->read(), Filter::related(
            'parent',
            ['code' => 'furniture'],
        )));
        self::assertSame([2, 6], $this->ids($this->products->read(), Filter::related(
            'category',
            Filter::related('parent', ['code' => 'furniture']),
        )));
        self::assertSame([1, 2], $this->ids($this->products->read(), Filter::related(
            'tags',
            Filter::related('category', Filter::related('parent', ['code' => 'furniture'])),
        )));
    }

    public function testRootDefaultLocaleIsInheritedEvenWhenTheRootDoesNotNeedATranslationJoin(): void
    {
        self::assertSame([2], $this->ids($this->products->read(), Filter::related('tags', ['name' => 'Sale'])));
        self::assertStringNotContainsString('product_translations', $this->log->sql[0]);
        self::assertSame(['en', 'Sale'], array_values($this->log->parameters[0]));
    }

    public function testExplicitLocaleAndFallbackAreInheritedThroughNestedRelatedFilters(): void
    {
        $query = $this->products->withLocale('uk', 'en');
        self::assertSame([1], $this->ids($query, Filter::related('tags', ['name' => 'Blue Large'])));
        self::assertSame([2], $this->ids($query, Filter::related('tags', ['name' => ''])));
        self::assertSame([1, 2], $this->ids($query, Filter::related(
            'tags',
            Filter::related('category', ['name' => 'Chairs']),
        )));
        self::assertSame(['uk', 'en', 'Chairs'], array_values($this->log->parameters[2]));
    }

    public function testFirstTranslatedDescendantSuppliesItsDefaultAndPassesItDown(): void
    {
        $products = $this->productRepository(translated: false);
        self::assertSame([2], $this->ids($products->read(), Filter::related('tags', ['name' => 'Розпродаж'])));
        self::assertSame([2, 6], $this->ids($products->read(), Filter::related('category', ['name' => 'Stühle'])));
        self::assertSame([], $this->ids($products->read(), Filter::related(
            'tags',
            Filter::related('category', ['name' => 'Stühle']),
        )));
        self::assertSame([1], $this->ids($products->withLocale('uk', 'en'), Filter::related(
            'tags',
            ['name' => 'Blue Large'],
        )));
    }

    public function testSiblingAndNestedExistsHaveIndependentAliasesAndParameters(): void
    {
        $criteria = Filter::all(
            Filter::related('tags', ['name' => 'Sale']),
            Filter::related('tags', ['name' => 'Disabled']),
            Filter::related('tags', Filter::related('category', ['name' => 'Chairs'])),
        );
        self::assertSame([2], $this->ids($this->products->read(), $criteria));
        self::assertSame(4, substr_count($this->log->sql[0], 'EXISTS (SELECT 1'));
        preg_match_all('/FROM "(?:tags|categories)" (br_base_related[0-9]+)/', $this->log->sql[0], $matches);
        self::assertCount(4, array_unique($matches[1]));
        self::assertCount(6, $this->log->parameters[0]);
        self::assertCount(3, array_filter($this->log->parameters[0], static fn ($value): bool => $value === 'en'));
    }

    public function testFoldedRelatedBranchesDoNotCreateExistsJoinsOrBindings(): void
    {
        $builder = $this->connection->createQueryBuilder()->select('1')->from('products', 'br_base');
        $db = new Database($this->connection);
        $mapping = $this->productMapping();
        $fields = new FieldResolver($db, $mapping, $builder, 'br_base', new Locale('uk', 'en'));
        $compiler = new PredicateCompiler($db, $mapping, $builder, $fields);
        self::assertTrue($compiler->compile(Filter::any([], Filter::related('tags', ['name' => 'Ignored']))));
        self::assertSame([], $builder->getParameters());
        self::assertSame('SELECT 1 FROM products br_base', $builder->getSQL());

        self::assertSame(2, $this->products->read()->count(Filter::related('tags', Filter::any(
            [],
            ['name' => 'Ignored'],
        ))));
        self::assertStringNotContainsString('tag_translations', $this->log->sql[0]);
        self::assertSame([], $this->log->parameters[0]);
        self::assertSame(5, $this->products->read()->count(Filter::not(Filter::related('tags', ['id IN' => []]))));
    }

    #[DataProvider('invalidCriteria')]
    public function testEveryRelatedBranchIsValidatedBeforeSqlEvenWhenItWouldBeFolded(Filter $criteria): void
    {
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->products->read()->count($criteria);
        } finally {
            self::assertSame([], $this->log->sql);
        }
    }

    public static function invalidCriteria(): iterable
    {
        yield 'unknown relation' => [Filter::related('unknown')];
        yield 'field belongs to root rather than target' => [Filter::related('tags', ['sku' => 'one'])];
        yield 'nested unknown relation' => [Filter::related('tags', Filter::related('unknown'))];
        yield 'unknown nested field' => [Filter::related('tags', Filter::related('category', ['slug' => 'wrong']))];
        yield 'unknown branch under true' => [Filter::any([], Filter::related('unknown'))];
        yield 'unknown target field under false' => [Filter::all(['id IN' => []], Filter::related('tags', ['x' => 1]))];
        yield 'invalid nested branch under true' => [Filter::related('tags', Filter::any([], ['name LIKE' => null]))];
        yield 'deleted visibility without metadata' => [Filter::related('detail', visibility: Visibility::Deleted)];
    }

    #[DataProvider('writeCriteria')]
    public function testWriteModeRejectsRelatedPredicatesIncludingFoldedBranches(Filter $criteria): void
    {
        foreach (['updateBy', 'deleteBy'] as $operation) {
            try {
                if ($operation === 'updateBy') {
                    $this->products->updateBy($criteria, ['sku' => 'changed']);
                } else {
                    $this->products->deleteBy($criteria);
                }
                self::fail('Related write criteria must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('only for read queries', $exception->getMessage());
                self::assertSame([], $this->log->sql);
            }
        }
    }

    public static function writeCriteria(): iterable
    {
        yield 'direct' => [Filter::related('tags')];
        yield 'under true' => [Filter::any([], Filter::related('tags'))];
        yield 'under false' => [Filter::all(['id IN' => []], Filter::related('tags'))];
        yield 'empty related set' => [Filter::related('tags', ['id IN' => []])];
    }

    public function testDotPathsAndSqlFragmentsAreNotRelationNames(): void
    {
        foreach (['tags.category', 'tags WHERE 1=1', '', 'tags; DROP TABLE tags'] as $name) {
            try {
                Filter::related($name);
                self::fail('A related filter must use a plain relation name.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->log->sql);
            }
        }
    }

    public function testGlobalParameterBudgetIncludesRootFieldsNestedValuesAndEveryLocaleJoin(): void
    {
        foreach ([false, true] as $extraParameter) {
            $this->clearLog();
            $criteria = Filter::all(
                ['id IN' => range(1, $extraParameter ? 898 : 897)],
                Filter::related('tags', ['name' => 'Sale']),
            );
            if ($extraParameter) {
                try {
                    $this->products->read()->rows(['id', 'name'], $criteria);
                    self::fail('Root and related locale bindings must share one parameter budget.');
                } catch (InvalidArgumentException) {
                    self::assertSame([], $this->log->sql);
                }
            } else {
                self::assertSame(
                    [['id' => 2, 'name' => null]],
                    $this->products->read()->rows(['id', 'name'], $criteria),
                );
                self::assertCount(900, $this->log->parameters[0]);
            }
        }

        $this->clearLog();
        $criteria = Filter::all(
            ['id IN' => range(1, 894)],
            Filter::related('tags', ['name' => 'Розпродаж']),
            Filter::related('tags', ['name' => '']),
        );
        self::assertSame([2], $this->ids($this->products->withLocale('uk', 'en'), $criteria));
        self::assertCount(900, $this->log->parameters[0]);
    }

    public function testExistingNamedParametersAreNotOverwrittenByRelatedLocalesOrValues(): void
    {
        $builder = $this->connection->createQueryBuilder()->select('br_base.id')->from('products', 'br_base');
        $builder->setParameter('locale1', 'keep', Types::STRING);
        $builder->setParameter('predicate1', 'keep', Types::STRING);
        $builder->setParameter('dcValue1', 'keep', Types::STRING);
        $db = new Database($this->connection);
        $mapping = $this->productMapping();
        $fields = new FieldResolver($db, $mapping, $builder, 'br_base', null);
        $sql = (new PredicateCompiler($db, $mapping, $builder, $fields))
            ->compile(Filter::related('tags', ['name' => 'Sale']));
        self::assertIsString($sql);
        self::assertSame([
            'locale1' => 'keep',
            'predicate1' => 'keep',
            'dcValue1' => 'keep',
            'locale2' => 'en',
            'predicate2' => 'Sale',
        ], $builder->getParameters());
        $builder->where($sql)->andWhere(':locale1 = :predicate1 AND :dcValue1 = :locale1')->orderBy('br_base.id');
        self::assertSame([2, 5], array_map('intval', $builder->executeQuery()->fetchFirstColumn()));
    }

    public function testStringKeysReservedIdentifiersAndValuesRemainSafe(): void
    {
        $this->connection->executeStatement('CREATE TABLE "order" ("key" TEXT PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE "group" ("select" TEXT PRIMARY KEY, "where" TEXT)');
        $this->connection->executeStatement('CREATE TABLE "join" ("order" TEXT, "group" TEXT)');
        $value = "x' OR 1=1 --";
        $this->connection->executeStatement('INSERT INTO "order" VALUES (?), (?)', ['01', '1']);
        $this->connection->executeStatement('INSERT INTO "group" VALUES (?, ?)', ['tag-key', $value]);
        $this->connection->executeStatement('INSERT INTO "join" VALUES (?, ?)', ['01', 'tag-key']);
        $tags = new Mapping(
            table: 'group',
            columns: ['select' => Types::STRING, 'where' => Types::STRING],
            hydrate: self::forbiddenHydration(...),
            primaryKey: 'select',
            generatedId: false,
        );
        $repository = new Repository($this->connection, new Mapping(
            table: 'order',
            columns: ['key' => Types::STRING],
            hydrate: self::forbiddenHydration(...),
            primaryKey: 'key',
            generatedId: false,
            relations: [
                'groups' => new BelongsToMany(
                    static fn (): Mapping => $tags,
                    'join',
                    'order',
                    'group',
                    self::forbiddenAssign(...),
                ),
            ],
        ));
        $this->clearLog();

        self::assertSame(
            [['key' => '01']],
            $repository->read()->rows(['key'], Filter::related('groups', ['where' => $value])),
        );
        self::assertStringNotContainsString($value, $this->log->sql[0]);
        self::assertSame([$value], array_values($this->log->parameters[0]));
    }

    public function testRelationsAndTranslationJoinsRespectTheReferencedPrimaryKeyCollation(): void
    {
        $statements = [
            'CREATE TABLE case_owners (key TEXT COLLATE NOCASE PRIMARY KEY, target_key TEXT)',
            'CREATE TABLE case_labels (key TEXT COLLATE NOCASE PRIMARY KEY, owner_key TEXT)',
            'CREATE TABLE case_links (owner_key TEXT, label_key TEXT)',
            'CREATE TABLE case_owner_translations (owner_key TEXT, locale TEXT, name TEXT, UNIQUE(owner_key, locale))',
            'CREATE TABLE case_label_translations (label_key TEXT, locale TEXT, name TEXT, UNIQUE(label_key, locale))',
            "INSERT INTO case_owners VALUES ('Owner', 'label')",
            "INSERT INTO case_labels VALUES ('Label', 'owner')",
            "INSERT INTO case_links VALUES ('owner', 'label')",
            "INSERT INTO case_owner_translations VALUES ('owner', 'en', 'Owner translation')",
            "INSERT INTO case_label_translations VALUES ('label', 'en', 'Label translation')",
        ];
        foreach ($statements as $statement) {
            $this->connection->executeStatement($statement);
        }
        $labels = new Mapping(
            table: 'case_labels',
            columns: ['key' => Types::STRING, 'owner_key' => Types::STRING],
            hydrate: self::forbiddenHydration(...),
            primaryKey: 'key',
            generatedId: false,
            translations: new Translations('case_label_translations', 'label_key', ['name' => Types::STRING], 'uk'),
        );
        $target = static fn (): Mapping => $labels;
        $repository = new Repository($this->connection, new Mapping(
            table: 'case_owners',
            columns: ['key' => Types::STRING, 'target_key' => Types::STRING],
            hydrate: self::forbiddenHydration(...),
            primaryKey: 'key',
            generatedId: false,
            translations: new Translations('case_owner_translations', 'owner_key', ['name' => Types::STRING], 'en'),
            relations: [
                'featured' => new BelongsTo($target, 'target_key', self::forbiddenAssign(...)),
                'single' => new HasOne($target, 'owner_key', self::forbiddenAssign(...)),
                'many' => new HasMany($target, 'owner_key', self::forbiddenAssign(...)),
                'linked' => new BelongsToMany(
                    $target,
                    'case_links',
                    'owner_key',
                    'label_key',
                    self::forbiddenAssign(...),
                ),
            ],
        ));

        foreach (['featured', 'single', 'many', 'linked'] as $relation) {
            self::assertSame([['key' => 'Owner']], $repository->read()->rows(['key'], Filter::related($relation)));
            self::assertSame(
                [['key' => 'Owner', 'name' => 'Owner translation']],
                $repository->read()->rows(['key', 'name'], Filter::related($relation, ['name' => 'Label translation'])),
            );
        }
    }

    private function productRepository(bool $translated = true): Repository
    {
        return new Repository($this->connection, $this->productMapping($translated), parameterLimit: 900);
    }

    private function productMapping(bool $translated = true): Mapping
    {
        $details = new Mapping(
            'details',
            ['id' => Types::INTEGER, 'product_id' => Types::INTEGER, 'note' => Types::STRING],
            self::forbiddenHydration(...),
        );
        $reviews = new Mapping(
            'reviews',
            [
                'id' => Types::INTEGER,
                'product_id' => Types::INTEGER,
                'rating' => Types::INTEGER,
                'deleted_at' => Types::DATETIME_IMMUTABLE,
            ],
            self::forbiddenHydration(...),
            softDeleteColumn: 'deleted_at',
        );

        return new Mapping(
            table: 'products',
            columns: [
                'id' => Types::INTEGER,
                'category_id' => Types::INTEGER,
                'sku' => Types::STRING,
                'deleted_at' => Types::DATETIME_IMMUTABLE,
            ],
            hydrate: self::forbiddenHydration(...),
            softDeleteColumn: 'deleted_at',
            translations: $translated
                ? new Translations('product_translations', 'product_id', ['name' => Types::STRING], 'en')
                : null,
            relations: [
                'category' => new BelongsTo(
                    fn (): Mapping => $this->categories,
                    'category_id',
                    self::forbiddenAssign(...),
                ),
                'tags' => new BelongsToMany(
                    fn (): Mapping => $this->tags,
                    'product_tag',
                    'product_id',
                    'tag_id',
                    self::forbiddenAssign(...),
                ),
                'detail' => new HasOne(static fn (): Mapping => $details, 'product_id', self::forbiddenAssign(...)),
                'reviews' => new HasMany(
                    static fn (): Mapping => $reviews,
                    'product_id',
                    self::forbiddenAssign(...),
                ),
            ],
        );
    }

    private function createTables(): void
    {
        $statements = [
            'CREATE TABLE products (id INTEGER PRIMARY KEY, category_id INTEGER, sku TEXT, deleted_at DATETIME)',
            'CREATE TABLE categories (id INTEGER PRIMARY KEY, parent_id INTEGER, code TEXT, deleted_at DATETIME)',
            'CREATE TABLE tags (id INTEGER PRIMARY KEY, category_id INTEGER, slug TEXT, color TEXT, size TEXT, '
                . 'enabled INTEGER, deleted_at DATETIME)',
            'CREATE TABLE details (id INTEGER PRIMARY KEY, product_id INTEGER UNIQUE, note TEXT)',
            'CREATE TABLE reviews (id INTEGER PRIMARY KEY, product_id INTEGER, rating INTEGER, deleted_at DATETIME)',
            'CREATE TABLE product_tag (product_id INTEGER, tag_id INTEGER, PRIMARY KEY (product_id, tag_id))',
            'CREATE TABLE product_translations '
                . '(product_id INTEGER, locale TEXT, name TEXT, UNIQUE(product_id, locale))',
            'CREATE TABLE tag_translations (tag_id INTEGER, locale TEXT, name TEXT, UNIQUE(tag_id, locale))',
            'CREATE TABLE category_translations '
                . '(category_id INTEGER, locale TEXT, name TEXT, UNIQUE(category_id, locale))',
        ];
        foreach ($statements as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    private function seed(): void
    {
        foreach ([[1, null, 'furniture', null], [2, 1, 'chairs', null], [3, 1, 'hidden', '2026-01-01']] as $row) {
            $this->connection->executeStatement('INSERT INTO categories VALUES (?, ?, ?, ?)', $row);
        }
        foreach ([[1, 1], [2, 2], [3, 3], [4, null], [5, 1], [6, 2]] as [$id, $category]) {
            $this->connection->insert('products', [
                'id' => $id,
                'category_id' => $category,
                'sku' => 'sku-' . $id,
                'deleted_at' => $id === 5 ? '2026-01-01' : null,
            ]);
        }
        $tags = [
            [1, 1, 'red-small', 'red', 'small', 1, null],
            [2, 2, 'blue-large', 'blue', 'large', 1, null],
            [3, 2, 'red-large', 'red', 'large', 1, null],
            [4, 1, 'disabled', 'red', 'large', 0, null],
            [5, 3, 'hidden', 'red', 'large', 1, '2026-01-01'],
            [6, 1, 'alpha', 'blue', 'small', 1, null],
        ];
        foreach ($tags as $row) {
            $this->connection->executeStatement('INSERT INTO tags VALUES (?, ?, ?, ?, ?, ?, ?)', $row);
        }
        foreach ([[1, 1], [1, 2], [1, 6], [2, 3], [2, 4], [3, 5], [5, 3], [6, 5]] as $row) {
            $this->connection->executeStatement('INSERT INTO product_tag VALUES (?, ?)', $row);
        }
        $this->connection->executeStatement("INSERT INTO details VALUES (1, 1, 'good'), (2, 2, 'bad')");
        $this->connection->executeStatement("INSERT INTO reviews VALUES
            (1, 1, 5, NULL), (2, 1, 1, NULL), (3, 2, 4, NULL), (4, 3, 5, '2026-01-01')");
        $tagTranslations = [
            [1, 'en', 'Red Small'], [1, 'uk', 'Малий'],
            [2, 'en', 'Blue Large'], [2, 'uk', null],
            [3, 'en', 'Sale'], [3, 'uk', 'Розпродаж'],
            [4, 'en', 'Disabled'], [4, 'uk', ''],
        ];
        foreach ($tagTranslations as $row) {
            $this->connection->executeStatement('INSERT INTO tag_translations VALUES (?, ?, ?)', $row);
        }
        $categoryTranslations = [
            [1, 'en', 'Furniture'], [1, 'uk', 'Меблі'], [1, 'de', 'Möbel'],
            [2, 'en', 'Chairs'], [2, 'uk', null], [2, 'de', 'Stühle'],
        ];
        foreach ($categoryTranslations as $row) {
            $this->connection->executeStatement('INSERT INTO category_translations VALUES (?, ?, ?)', $row);
        }
    }

    private function ids(ReadQuery $query, array|Filter $criteria): array
    {
        return array_column($query->rows(['id'], $criteria, ['id' => 'ASC']), 'id');
    }

    private function clearLog(): void
    {
        $this->log->sql = [];
        $this->log->parameters = [];
    }

    private static function forbiddenHydration(array $row): never
    {
        throw new RuntimeException('Predicate reads must not hydrate models.');
    }

    private static function forbiddenAssign(object $owner, object|array|null $related): never
    {
        throw new RuntimeException('Related predicates must not assign models.');
    }
}
