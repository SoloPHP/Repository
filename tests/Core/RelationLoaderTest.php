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
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Solo\Repository\Filter;
use Solo\Repository\Load;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsTo;
use Solo\Repository\Relations\BelongsToMany;
use Solo\Repository\Relations\HasMany;
use Solo\Repository\Relations\HasOne;
use Solo\Repository\Repository;
use Solo\Repository\Translations;
use Solo\Repository\Visibility;
use Stringable;
use UnexpectedValueException;
use WeakReference;

final class RelationLoaderTest extends TestCase
{
    private Connection $connection;
    private AbstractLogger $logger;
    private Mapping $categories;
    private Mapping $profiles;
    private Mapping $notes;
    private Mapping $tags;
    private Repository $items;

    protected function setUp(): void
    {
        $this->logger = new class extends AbstractLogger {
            public array $queries = [];
            public array $parameters = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $this->queries[] = $context['sql'];
                    $this->parameters[] = $context['params'] ?? [];
                }
            }
        };
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->logger)]);
        $this->connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            $configuration,
        );
        $schema = [
            'CREATE TABLE categories (id INTEGER PRIMARY KEY, parent_id INTEGER, deleted_at DATETIME)',
            'CREATE TABLE category_translations (category_id INTEGER, locale TEXT, name TEXT, '
                . 'UNIQUE(category_id, locale))',
            'CREATE TABLE items (id INTEGER PRIMARY KEY, category_id INTEGER, sku TEXT, deleted_at DATETIME)',
            'CREATE TABLE item_translations (item_id INTEGER, locale TEXT, name TEXT, UNIQUE(item_id, locale))',
            'CREATE TABLE profiles (id INTEGER PRIMARY KEY, item_id INTEGER, label TEXT)',
            'CREATE TABLE notes (id INTEGER PRIMARY KEY, item_id INTEGER, body TEXT, '
                . 'enabled BOOLEAN, deleted_at DATETIME)',
            'CREATE TABLE tags (id INTEGER PRIMARY KEY, slug TEXT, enabled BOOLEAN, deleted_at DATETIME)',
            'CREATE TABLE tag_translations (tag_id INTEGER, locale TEXT, name TEXT, UNIQUE(tag_id, locale))',
            'CREATE TABLE item_tags (item_id INTEGER, tag_id INTEGER, UNIQUE(item_id, tag_id))',
        ];
        foreach ($schema as $sql) {
            $this->connection->executeStatement($sql);
        }
        $this->connection->executeStatement(
            "INSERT INTO categories VALUES (10, 100, NULL), (20, 100, '2026-01-01 00:00:00'), (100, NULL, NULL)"
        );
        foreach ([10 => 'Category', 20 => 'Hidden category', 100 => 'Parent'] as $id => $name) {
            foreach (['en', 'uk', 'fr'] as $locale) {
                $this->connection->insert('category_translations', [
                    'category_id' => $id, 'locale' => $locale, 'name' => $name . ' ' . strtoupper($locale),
                ]);
            }
        }
        foreach ([1 => 10, 2 => 10, 3 => 20, 4 => null, 5 => 10] as $id => $category) {
            $this->connection->insert('items', [
                'id' => $id,
                'category_id' => $category,
                'sku' => 'SKU-' . $id,
                'deleted_at' => $id === 5 ? '2026-01-01 00:00:00' : null,
            ]);
            foreach (['en', 'uk'] as $locale) {
                $this->connection->insert('item_translations', [
                    'item_id' => $id, 'locale' => $locale, 'name' => 'Item ' . $id . ' ' . strtoupper($locale),
                ]);
            }
        }
        $this->connection->executeStatement("INSERT INTO profiles VALUES (1, 1, 'One'), (2, 2, 'Two')");
        $this->connection->executeStatement(
            "INSERT INTO notes VALUES (21, 1, 'zeta', 0, NULL), (22, 1, 'alpha', 1, NULL), "
            . "(23, 2, 'beta', 1, '2026-01-01 00:00:00'), (24, 2, 'gamma', 1, NULL), "
            . "(25, 3, 'different', 1, NULL)"
        );
        $this->connection->executeStatement(
            "INSERT INTO tags VALUES (101, 'first', 1, NULL), (102, 'second', 1, NULL), "
            . "(103, 'disabled', 0, NULL), (104, 'hidden', 1, '2026-01-01 00:00:00')"
        );
        foreach ([101 => 'Zulu', 102 => 'Alpha', 103 => 'Bravo', 104 => 'Hidden'] as $id => $name) {
            $this->connection->insert('tag_translations', ['tag_id' => $id, 'locale' => 'en', 'name' => $name]);
        }
        $this->connection->executeStatement(
            "INSERT INTO tag_translations VALUES (101, 'uk', NULL), (102, 'uk', 'Beta UK'), "
            . "(103, 'uk', 'Zero UK'), (104, 'uk', 'Hidden UK')"
        );
        $this->connection->executeStatement(
            'INSERT INTO item_tags VALUES (1, 101), (1, 102), (1, 103), (1, 104), (2, 102), (3, 103), (5, 101)'
        );

        $parents = $this->categoryMapping(defaultLocale: 'fr');
        $this->categories = $this->categoryMapping([
            'parent' => new BelongsTo(static fn(): Mapping => $parents, 'parent_id', self::assign('parent')),
        ], 'uk');
        $this->profiles = new Mapping(
            'profiles',
            ['id' => Types::INTEGER, 'item_id' => Types::INTEGER, 'label' => Types::STRING],
            self::model(...),
        );
        $this->notes = new Mapping(
            'notes',
            [
                'id' => Types::INTEGER, 'item_id' => Types::INTEGER, 'body' => Types::STRING,
                'enabled' => Types::BOOLEAN, 'deleted_at' => Types::DATETIME_IMMUTABLE,
            ],
            self::model(...),
            softDeleteColumn: 'deleted_at',
        );
        $this->tags = new Mapping(
            'tags',
            [
                'id' => Types::INTEGER, 'slug' => Types::STRING,
                'enabled' => Types::BOOLEAN, 'deleted_at' => Types::DATETIME_IMMUTABLE,
            ],
            self::model(...),
            softDeleteColumn: 'deleted_at',
            translations: new Translations('tag_translations', 'tag_id', ['name' => Types::STRING], 'en'),
        );
        $this->items = $this->itemRepository();
        $this->resetLog();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testFourRelationKindsLoadInSixQueriesForPrivateImmutableModels(): void
    {
        $items = $this->items->with([
            'category', 'profile',
            new Load('notes', orderBy: ['body' => 'ASC']),
            new Load('tags', orderBy: ['name' => 'ASC']),
        ])->findBy(orderBy: ['id' => 'ASC']);

        self::assertSame([1, 2, 3, 4], $this->ids($items));
        self::assertCount(6, $this->logger->queries);
        self::assertSame(10, $items[0]->relation('category')->value('id'));
        self::assertSame('One', $items[0]->relation('profile')->value('label'));
        self::assertSame([22, 21], $this->ids($items[0]->relation('notes')));
        self::assertSame([102, 103, 101], $this->ids($items[0]->relation('tags')));
        self::assertNull($items[2]->relation('category'));
        self::assertNull($items[3]->relation('category'));
        self::assertNull($items[3]->relation('profile'));
        self::assertSame([], $items[3]->relation('notes'));
        self::assertSame([], $items[3]->relation('tags'));
        self::assertTrue($items[3]->loaded('tags'));
        self::assertObjectNotHasProperty('id', $items[0]);
    }

    public function testCatalogHasFourQueriesWithSharedBelongsToTargets(): void
    {
        $items = $this->items->with(['category', 'tags'])->findBy(['id IN' => [1, 2]], ['id' => 'ASC']);

        self::assertCount(4, $this->logger->queries);
        self::assertSame(10, $items[0]->relation('category')->value('id'));
        self::assertSame($items[0]->relation('category'), $items[1]->relation('category'));
        self::assertSame(['en', 10], array_values($this->logger->parameters[1]));
    }

    public function testSharedIntegerBelongsToTargetIsFetchedAndHydratedOnce(): void
    {
        $hydrations = 0;
        $categories = new Mapping(
            'categories',
            ['id' => Types::INTEGER, 'parent_id' => Types::INTEGER, 'deleted_at' => Types::DATETIME_IMMUTABLE],
            static function (array $row) use (&$hydrations): object {
                $hydrations++;
                return self::model($row);
            },
            softDeleteColumn: 'deleted_at',
        );
        $items = $this->itemRepository(relations: [
            'category' => new BelongsTo(static fn(): Mapping => $categories, 'category_id', self::assign('category')),
        ]);

        $models = $items->with(['category'])->findBy(['id IN' => [1, 2, 3, 4]], ['id' => 'ASC']);

        self::assertSame(1, $hydrations);
        self::assertSame($models[0]->relation('category'), $models[1]->relation('category'));
        self::assertNull($models[2]->relation('category'));
        self::assertNull($models[3]->relation('category'));
        self::assertSame([10, 20], array_values($this->logger->parameters[1]));
        self::assertStringNotContainsString('"items"', $this->logger->queries[1]);
    }

    public function testSharedStringBelongsToTargetIsJoinedButHydratedOnce(): void
    {
        $this->connection->executeStatement('CREATE TABLE case_parents (id TEXT PRIMARY KEY COLLATE NOCASE)');
        $this->connection->executeStatement(
            'CREATE TABLE case_owners (id TEXT PRIMARY KEY COLLATE NOCASE, parent_id TEXT)'
        );
        $this->connection->executeStatement("INSERT INTO case_parents VALUES ('parent-a')");
        $this->connection->executeStatement(
            "INSERT INTO case_owners VALUES ('one', 'PARENT-A'), ('three', NULL), ('two', 'parent-a')"
        );
        $hydrations = 0;
        $parents = new Mapping(
            'case_parents',
            ['id' => Types::STRING],
            static function (array $row) use (&$hydrations): object {
                $hydrations++;
                return self::model($row);
            },
            generatedId: false,
        );
        $owners = new Repository($this->connection, new Mapping(
            'case_owners',
            ['id' => Types::STRING, 'parent_id' => Types::STRING],
            self::model(...),
            generatedId: false,
            relations: [
                'parent' => new BelongsTo(static fn(): Mapping => $parents, 'parent_id', self::assign('parent')),
            ],
        ));
        $this->resetLog();

        $models = $owners->with(['parent'])->findBy(orderBy: ['id' => 'ASC']);

        self::assertSame(1, $hydrations);
        self::assertSame('parent-a', $models[0]->relation('parent')->value('id'));
        self::assertNull($models[1]->relation('parent'));
        self::assertSame($models[0]->relation('parent'), $models[2]->relation('parent'));
        self::assertCount(2, $this->logger->queries);
        self::assertStringContainsString('"case_owners"', $this->logger->queries[1]);
    }

    public function testLoadFiltersCollectionsWithoutRemovingOwners(): void
    {
        $items = $this->items->with([
            new Load('notes', criteria: ['enabled' => true], orderBy: ['body' => 'ASC']),
            new Load('tags', criteria: ['enabled' => false]),
        ])->findBy(orderBy: ['id' => 'ASC']);

        self::assertSame([1, 2, 3, 4], $this->ids($items));
        self::assertSame([22], $this->ids($items[0]->relation('notes')));
        self::assertSame([103], $this->ids($items[0]->relation('tags')));
        self::assertSame([], $items[1]->relation('tags'));
        self::assertSame([], $items[3]->relation('tags'));
    }

    public function testRelatedPredicateDoesNotRestrictLoadedCollection(): void
    {
        $items = $this->items->with([new Load('tags', orderBy: ['id' => 'ASC'])])->findBy(
            Filter::related('tags', ['slug' => 'first']),
        );

        self::assertSame([1], $this->ids($items));
        self::assertSame([101, 102, 103], $this->ids($items[0]->relation('tags')));
    }

    public function testOwnerVisibilityDoesNotLeakIntoRelationVisibility(): void
    {
        $item = $this->items->with(['category', 'tags'])->withVisibility(Visibility::Deleted)->find(5);

        self::assertSame(10, $item->relation('category')->value('id'));
        self::assertSame([101], $this->ids($item->relation('tags')));
        $visible = $this->items->with([
            new Load('category', visibility: Visibility::All),
            new Load('tags', visibility: Visibility::Deleted),
        ])->findBy(['id IN' => [1, 3]], ['id' => 'ASC']);
        self::assertSame([104], $this->ids($visible[0]->relation('tags')));
        self::assertSame(20, $visible[1]->relation('category')->value('id'));
        self::assertSame([], $visible[1]->relation('tags'));
    }

    public function testNestedPathPreservesImmutableAssignmentsAndInheritsRootDefaultLocale(): void
    {
        $item = $this->items->with(['category.parent'])->find(1);

        self::assertSame('Item 1 EN', $item->value('name'));
        self::assertSame('Category EN', $item->relation('category')->value('name'));
        self::assertSame('Parent EN', $item->relation('category')->relation('parent')->value('name'));
        self::assertCount(3, $this->logger->queries);
    }

    public function testFirstTranslatedEntityDefinesDefaultBelowAnUntranslatedRoot(): void
    {
        $items = $this->itemRepository(translated: false);
        $default = $items->with(['category.parent'])->find(1);
        self::assertSame('Category UK', $default->relation('category')->value('name'));
        self::assertSame('Parent UK', $default->relation('category')->relation('parent')->value('name'));

        $explicit = $items->withLocale('en')->with(['category.parent'])->find(1);
        self::assertSame('Category EN', $explicit->relation('category')->value('name'));
        self::assertSame('Parent EN', $explicit->relation('category')->relation('parent')->value('name'));
    }

    public function testInheritedFallbackIsSharedByRelationProjectionFilterAndOrder(): void
    {
        $items = $this->items->withLocale('uk', 'en')->with([
            new Load('tags', criteria: ['name IN' => ['Zulu', 'Beta UK']], orderBy: ['name' => 'ASC']),
        ])->findBy(['id IN' => [1, 2, 3]], ['id' => 'ASC']);

        self::assertSame([102, 101], $this->ids($items[0]->relation('tags')));
        self::assertSame('Zulu', $items[0]->relation('tags')[1]->value('name'));
        self::assertSame([102], $this->ids($items[1]->relation('tags')));
        self::assertSame([], $items[2]->relation('tags'));
    }

    public function testNestedLoadOptionsApplyToTheExactEdge(): void
    {
        $item = $this->items->with([
            'category.parent',
            new Load('category.parent', criteria: ['name' => 'not found']),
        ])->find(1);

        self::assertSame(10, $item->relation('category')->value('id'));
        self::assertTrue($item->relation('category')->loaded('parent'));
        self::assertNull($item->relation('category')->relation('parent'));
    }

    public function testCollectionsUseTheRelationDefaultOrderUnlessTheLoadSetsOne(): void
    {
        $notes = $this->notes;
        $tags = $this->tags;
        $items = $this->itemRepository(relations: [
            'notes' => new HasMany(static fn(): Mapping => $notes, 'item_id', self::assign('notes'), ['body' => 'desc']),
            'tags' => new BelongsToMany(
                static fn(): Mapping => $tags,
                'item_tags',
                'item_id',
                'tag_id',
                self::assign('tags'),
                ['id' => 'DESC'],
            ),
        ]);

        $item = $items->with(['notes', 'tags'])->find(1);
        self::assertSame([21, 22], $this->ids($item->relation('notes')));
        self::assertSame([103, 102, 101], $this->ids($item->relation('tags')));

        $item = $items->with([new Load('notes', orderBy: ['body' => 'ASC']), new Load('tags', ['enabled' => true])])
            ->find(1);
        self::assertSame([22, 21], $this->ids($item->relation('notes')));
        self::assertSame([102, 101], $this->ids($item->relation('tags')));
    }

    public function testUnknownDefaultOrderFieldFailsBeforeOwnerSql(): void
    {
        $notes = $this->notes;
        $items = $this->itemRepository(relations: [
            'notes' => new HasMany(static fn(): Mapping => $notes, 'item_id', self::assign('notes'), ['missing' => 'ASC']),
        ]);
        $this->resetLog();

        $this->expectException(InvalidArgumentException::class);
        try {
            $items->with(['notes'])->find(1);
        } finally {
            self::assertSame([], $this->logger->queries);
        }
    }

    public function testRootLimitIsAppliedBeforeCollections(): void
    {
        $items = $this->items->with(['notes', 'tags'])->findBy(orderBy: ['id' => 'ASC'], limit: 1);

        self::assertSame([1], $this->ids($items));
        self::assertCount(2, $items[0]->relation('notes'));
        self::assertCount(3, $items[0]->relation('tags'));
        self::assertCount(4, $this->logger->queries);
        self::assertStringContainsString('LIMIT 1', $this->logger->queries[0]);
        self::assertStringNotContainsString('LIMIT', $this->logger->queries[3]);
    }

    public function testEmptyOwnersAndEmptyMembershipDoNotLoadUnneededTargets(): void
    {
        self::assertSame([], $this->items->with(['category.parent', 'tags'])->findBy(['id' => 999]));
        self::assertCount(1, $this->logger->queries);
        $this->resetLog();

        $item = $this->items->with(['category', 'tags'])->find(4);
        self::assertNull($item->relation('category'));
        self::assertSame([], $item->relation('tags'));
        self::assertCount(2, $this->logger->queries);
    }

    public function testWithBranchesAreReusableAndScalarReadsIgnoreIncludes(): void
    {
        $full = $this->items->with(['category', 'tags']);
        $bare = $full->with([]);
        self::assertTrue($full->find(1)->loaded('tags'));
        self::assertFalse($bare->find(1)->loaded('tags'));
        self::assertTrue($full->find(1)->loaded('tags'));
        $this->resetLog();

        self::assertSame(4, $full->count());
        self::assertTrue($full->exists(['id' => 1]));
        self::assertCount(2, $this->logger->queries);
        self::assertStringNotContainsString('item_tags', implode(' ', $this->logger->queries));
        $this->resetLog();
        try {
            $full->rows(['id']);
            self::fail('Rows cannot include object relations.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->logger->queries);
        }
    }

    public function testZeroLimitStillValidatesLoadsWithoutSql(): void
    {
        self::assertSame([], $this->items->with(['category.parent', 'tags'])->findBy(limit: 0));
        self::assertSame([], $this->logger->queries);

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->items->with(['category.missing'])->findBy(limit: 0);
        } finally {
            self::assertSame([], $this->logger->queries);
        }
    }

    public function testInvalidNestedPathsAndLoadFiltersFailBeforeOwnerSql(): void
    {
        $loads = [
            ['category.missing'],
            [new Load('tags', criteria: ['missing' => 1])],
            [new Load('tags', orderBy: ['missing' => 'ASC'])],
            [new Load('profile', visibility: Visibility::Deleted)],
        ];
        foreach ($loads as $load) {
            try {
                $this->items->with($load)->find(999);
                self::fail('All relation paths and settings must be validated before reading owners.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->logger->queries);
            }
        }
    }

    public function testHasOneRejectsMultipleMatchingRows(): void
    {
        // Deliberately violate the schema contract to exercise the loader's cardinality check.
        $this->connection->insert('profiles', ['id' => 3, 'item_id' => 1, 'label' => 'Duplicate']);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('HasOne');
        $this->items->with(['profile'])->find(1);
    }

    public function testAssignMustReturnAnObject(): void
    {
        $notes = $this->notes;
        $items = $this->itemRepository(relations: [
            'notes' => new HasMany(static fn(): Mapping => $notes, 'item_id', static fn(): string => 'invalid'),
        ]);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('assign');
        $items->with(['notes'])->find(1);
    }

    public function testKeysAreCapturedBeforeMapperChangesVisibleIdentity(): void
    {
        $items = $this->itemRepository(hydrate: static function (array $row): object {
            $row['id'] = 999;
            $row['category_id'] = 999;
            return self::model($row);
        });
        $item = $items->with(['category', 'profile', 'tags'])->find(1);

        self::assertSame(999, $item->value('id'));
        self::assertSame(10, $item->relation('category')->value('id'));
        self::assertSame(1, $item->relation('profile')->value('id'));
        self::assertCount(3, $item->relation('tags'));
    }

    public function testKeysetKeepsRelationsAndReleasesCompletedOwnerBatches(): void
    {
        $query = $this->items->withLocale('uk', 'en')->with(['category', 'tags']);
        $references = [];
        $ids = [];
        foreach ($query->iterateById(batchSize: 2) as $item) {
            if (count($ids) === 2) {
                foreach ($references as $reference) {
                    self::assertNull($reference->get(), 'Previous owner and related models must be released.');
                }
                $references = [];
            }
            $ids[] = $item->value('id');
            self::assertTrue($item->loaded('tags'));
            self::assertSame('Item ' . $item->value('id') . ' UK', $item->value('name'));
            if (count($ids) <= 2) {
                $references[] = WeakReference::create($item);
                $references[] = WeakReference::create($item->relation('category'));
                foreach ($item->relation('tags') as $tag) {
                    $references[] = WeakReference::create($tag);
                }
                unset($tag);
            }
        }

        self::assertSame([1, 2, 3, 4], $ids);
        self::assertCount(9, $this->logger->queries);
    }

    public function testLargeOwnerBatchesReserveLoadCriteriaParameters(): void
    {
        foreach (range(1000, 1901) as $id) {
            $this->connection->insert('items', ['id' => $id, 'sku' => 'Bulk-' . $id]);
        }
        $this->connection->insert('notes', ['id' => 1000, 'item_id' => 1000, 'body' => 'first', 'enabled' => 1]);
        $this->connection->insert('notes', ['id' => 1901, 'item_id' => 1901, 'body' => 'last', 'enabled' => 1]);
        $this->resetLog();

        $items = $this->items->with([new Load('notes', ['enabled' => true])])->findBy(
            ['id >=' => 1000],
            ['id' => 'ASC'],
        );
        self::assertCount(902, $items);
        self::assertSame([1000], $this->ids($items[0]->relation('notes')));
        self::assertSame([1901], $this->ids($items[901]->relation('notes')));
        self::assertCount(3, $this->logger->queries);
        self::assertCount(900, $this->logger->parameters[1]);
        self::assertCount(4, $this->logger->parameters[2]);
    }

    public function testEveryJoinedStringKeyBatchBindsOnlyItsOwnOwners(): void
    {
        $this->connection->executeStatement('CREATE TABLE batch_owners (id TEXT PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE batch_children (id TEXT PRIMARY KEY, owner_id TEXT)');
        foreach (['a', 'b', 'c'] as $id) {
            $this->connection->insert('batch_owners', ['id' => $id]);
            $this->connection->insert('batch_children', ['id' => $id . '-child', 'owner_id' => $id]);
        }
        $children = new Mapping(
            'batch_children',
            ['id' => Types::STRING, 'owner_id' => Types::STRING],
            self::model(...),
            generatedId: false,
        );
        $owners = new Repository($this->connection, new Mapping(
            'batch_owners',
            ['id' => Types::STRING],
            self::model(...),
            generatedId: false,
            relations: [
                'children' => new HasMany(static fn(): Mapping => $children, 'owner_id', self::assign('children')),
            ],
        ), parameterLimit: 2);
        $this->resetLog();

        $items = $owners->with(['children'])->findBy(orderBy: ['id' => 'ASC']);

        self::assertSame(
            [['a-child'], ['b-child'], ['c-child']],
            array_map(fn(object $item): array => $this->ids($item->relation('children')), $items),
        );
        // Each batch clones the prepared read, so no batch carries the keys of another.
        self::assertCount(3, $this->logger->queries);
        self::assertCount(2, $this->logger->parameters[1]);
        self::assertCount(1, $this->logger->parameters[2]);
    }

    public function testManyToManyFanoutBeyondParameterLimitKeepsGlobalCollectionOrder(): void
    {
        foreach (range(1000, 1904) as $id) {
            $this->connection->insert('tags', ['id' => $id, 'slug' => 'bulk-' . $id, 'enabled' => 1]);
            $this->connection->insert('tag_translations', [
                'tag_id' => $id, 'locale' => 'en', 'name' => sprintf('Name-%04d', 1904 - $id),
            ]);
            $this->connection->insert('item_tags', ['item_id' => 4, 'tag_id' => $id]);
        }
        $this->resetLog();

        $item = $this->items->with([new Load('tags', orderBy: ['name' => 'ASC'])])->find(4);
        self::assertSame(range(1904, 1000), $this->ids($item->relation('tags')));
        self::assertCount(3, $this->logger->queries);
        self::assertCount(2, $this->logger->parameters[2]);
        self::assertStringContainsString('EXISTS', $this->logger->queries[2]);
    }

    public function testLocaleParametersLeaveSpaceForRelationKeysBeforeOwnerSql(): void
    {
        $query = $this->items->withLocale('uk', 'en')->with([
            new Load('tags', ['id IN' => range(1, 897)]),
        ]);
        $item = $query->find(1);
        self::assertCount(3, $item->relation('tags'));
        self::assertCount(900, $this->logger->parameters[2]);
        $this->resetLog();

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->items->withLocale('uk', 'en')->with([
                new Load('tags', ['id IN' => range(1, 898)]),
            ])->find(1);
        } finally {
            self::assertSame([], $this->logger->queries);
        }
    }

    public function testTextKeysRemainDistinctWithoutPhpArrayKeyCoercion(): void
    {
        $this->connection->executeStatement('CREATE TABLE text_owners (id TEXT PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE text_children (id TEXT PRIMARY KEY, owner_id TEXT)');
        $this->connection->executeStatement("INSERT INTO text_owners VALUES ('0'), ('01'), ('1')");
        $this->connection->executeStatement("INSERT INTO text_children VALUES ('a', '01'), ('b', '1')");
        $children = new Mapping(
            'text_children',
            ['id' => Types::STRING, 'owner_id' => Types::STRING],
            self::model(...),
            generatedId: false,
        );
        $owners = new Repository($this->connection, new Mapping(
            'text_owners',
            ['id' => Types::STRING],
            self::model(...),
            generatedId: false,
            relations: ['children' => new HasMany(
                static fn(): Mapping => $children,
                'owner_id',
                self::assign('children'),
            )],
        ));

        $models = $owners->with(['children'])->findBy(orderBy: ['id' => 'ASC']);
        self::assertSame(['0', '01', '1'], $this->ids($models));
        self::assertSame([], $models[0]->relation('children'));
        self::assertSame(['a'], $this->ids($models[1]->relation('children')));
        self::assertSame(['b'], $this->ids($models[2]->relation('children')));
    }

    public function testStringKeyRelationsFollowDatabaseCollationWithDifferentlyCasedForeignKeys(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE case_parents (id TEXT PRIMARY KEY COLLATE NOCASE)'
        );
        $this->connection->executeStatement(
            'CREATE TABLE case_owners (id TEXT PRIMARY KEY COLLATE NOCASE, parent_id TEXT)'
        );
        $this->connection->executeStatement(
            'CREATE TABLE case_children (id TEXT PRIMARY KEY COLLATE NOCASE, owner_id TEXT)'
        );
        $this->connection->executeStatement(
            'CREATE TABLE case_pivot (owner_id TEXT, child_id TEXT, UNIQUE(owner_id, child_id))'
        );
        $this->connection->executeStatement("INSERT INTO case_parents VALUES ('parent-a')");
        $this->connection->executeStatement("INSERT INTO case_owners VALUES ('owner-a', 'PARENT-A')");
        $this->connection->executeStatement("INSERT INTO case_children VALUES ('child-a', 'OWNER-A')");
        $this->connection->executeStatement("INSERT INTO case_pivot VALUES ('OWNER-A', 'CHILD-A')");
        $parents = new Mapping(
            'case_parents',
            ['id' => Types::STRING],
            self::model(...),
            generatedId: false,
        );
        $children = new Mapping(
            'case_children',
            ['id' => Types::STRING, 'owner_id' => Types::STRING],
            self::model(...),
            generatedId: false,
        );
        $owners = new Repository($this->connection, new Mapping(
            'case_owners',
            ['id' => Types::STRING, 'parent_id' => Types::STRING],
            self::model(...),
            generatedId: false,
            relations: [
                'parent' => new BelongsTo(static fn(): Mapping => $parents, 'parent_id', self::assign('parent')),
                'one' => new HasOne(static fn(): Mapping => $children, 'owner_id', self::assign('one')),
                'many' => new HasMany(static fn(): Mapping => $children, 'owner_id', self::assign('many')),
                'linked' => new BelongsToMany(
                    static fn(): Mapping => $children,
                    'case_pivot',
                    'owner_id',
                    'child_id',
                    self::assign('linked'),
                ),
            ],
        ));
        $this->resetLog();

        $item = $owners->with(['parent', 'one', 'many', 'linked'])->find('OWNER-A');
        self::assertNotNull($item->relation('parent'));
        self::assertSame('parent-a', $item->relation('parent')->value('id'));
        self::assertSame('child-a', $item->relation('one')->value('id'));
        self::assertSame(['child-a'], $this->ids($item->relation('many')));
        self::assertSame(['child-a'], $this->ids($item->relation('linked')));
        self::assertCount(6, $this->logger->queries);
    }

    public function testInternalOwnerKeyDoesNotOverwriteMappedOrTranslatedFields(): void
    {
        // String keys load through an owner join, which selects the owner key under a free alias.
        $this->connection->executeStatement('CREATE TABLE text_owners (id TEXT PRIMARY KEY)');
        $this->connection->executeStatement(
            'CREATE TABLE text_profiles (id TEXT PRIMARY KEY, owner_id TEXT, br_owner_id TEXT)'
        );
        $this->connection->executeStatement(
            'CREATE TABLE text_profile_translations (profile_id TEXT, locale TEXT, br_owner_id_ TEXT, '
            . 'UNIQUE(profile_id, locale))'
        );
        $this->connection->executeStatement("INSERT INTO text_owners VALUES ('owner')");
        $this->connection->executeStatement("INSERT INTO text_profiles VALUES ('profile', 'owner', 'visible base')");
        $this->connection->executeStatement(
            "INSERT INTO text_profile_translations VALUES ('profile', 'en', 'visible translation')"
        );
        $profiles = new Mapping(
            'text_profiles',
            ['id' => Types::STRING, 'owner_id' => Types::STRING, 'br_owner_id' => Types::STRING],
            static function (array $row): object {
                self::assertSame(['id', 'owner_id', 'br_owner_id', 'br_owner_id_'], array_keys($row));
                return self::model($row);
            },
            generatedId: false,
            translations: new Translations(
                'text_profile_translations',
                'profile_id',
                ['br_owner_id_' => Types::STRING],
                'en',
            ),
        );
        $owners = new Repository($this->connection, new Mapping(
            'text_owners',
            ['id' => Types::STRING],
            self::model(...),
            generatedId: false,
            relations: [
                'profile' => new HasOne(static fn(): Mapping => $profiles, 'owner_id', self::assign('profile')),
            ],
        ));

        $profile = $owners->with(['profile'])->find('owner')->relation('profile');
        self::assertSame('visible base', $profile->value('br_owner_id'));
        self::assertSame('visible translation', $profile->value('br_owner_id_'));
    }

    private function itemRepository(
        bool $translated = true,
        ?array $relations = null,
        ?Closure $hydrate = null,
    ): Repository {
        return new Repository($this->connection, new Mapping(
            'items',
            [
                'id' => Types::INTEGER, 'category_id' => Types::INTEGER,
                'sku' => Types::STRING, 'deleted_at' => Types::DATETIME_IMMUTABLE,
            ],
            $hydrate ?? self::model(...),
            softDeleteColumn: 'deleted_at',
            translations: $translated
                ? new Translations('item_translations', 'item_id', ['name' => Types::STRING], 'en')
                : null,
            relations: $relations ?? [
                'category' => new BelongsTo(
                    fn(): Mapping => $this->categories,
                    'category_id',
                    self::assign('category'),
                ),
                'profile' => new HasOne(fn(): Mapping => $this->profiles, 'item_id', self::assign('profile')),
                'notes' => new HasMany(fn(): Mapping => $this->notes, 'item_id', self::assign('notes')),
                'tags' => new BelongsToMany(
                    fn(): Mapping => $this->tags,
                    'item_tags',
                    'item_id',
                    'tag_id',
                    self::assign('tags'),
                ),
            ],
        ), parameterLimit: 900);
    }

    private function categoryMapping(array $relations = [], string $defaultLocale = 'en'): Mapping
    {
        return new Mapping(
            'categories',
            ['id' => Types::INTEGER, 'parent_id' => Types::INTEGER, 'deleted_at' => Types::DATETIME_IMMUTABLE],
            self::model(...),
            softDeleteColumn: 'deleted_at',
            translations: new Translations(
                'category_translations',
                'category_id',
                ['name' => Types::STRING],
                $defaultLocale,
            ),
            relations: $relations,
        );
    }

    private static function model(array $row): object
    {
        return new class ($row) {
            private array $relations = [];

            public function __construct(private readonly array $row)
            {
            }

            public function value(string $column): mixed
            {
                return $this->row[$column];
            }

            public function relation(string $name): mixed
            {
                return $this->relations[$name];
            }

            public function loaded(string $name): bool
            {
                return array_key_exists($name, $this->relations);
            }

            public function with(string $name, mixed $related): self
            {
                $copy = clone $this;
                $copy->relations[$name] = $related;
                return $copy;
            }
        };
    }

    private static function assign(string $name): Closure
    {
        return static fn(object $owner, mixed $related): object => $owner->with($name, $related);
    }

    private function ids(array $models): array
    {
        return array_map(static fn(object $model): int|string => $model->value('id'), $models);
    }

    private function resetLog(): void
    {
        $this->logger->queries = [];
        $this->logger->parameters = [];
    }
}
