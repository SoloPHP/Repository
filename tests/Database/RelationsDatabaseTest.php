<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Database;

use Closure;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
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
use WeakReference;

final class RelationsDatabaseTest extends DatabaseTestCase
{
    private Repository $items;
    private Repository $categories;
    private Repository $profiles;
    private Repository $notes;
    private Repository $tags;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFixtureSchema();
        $this->seed();
        $this->createRepositories();
        $this->log->reset();
    }

    public function testFourKindsLoadWithoutMultiplyingOwners(): void
    {
        $items = $this->items->with([
            'category', 'profile',
            new Load('notes', orderBy: ['body' => 'ASC']),
            new Load('tags', orderBy: ['name' => 'ASC']),
        ])->findBy(orderBy: ['id' => 'ASC']);

        self::assertSame([1, 2, 3, 4], self::ids($items));
        self::assertCount(6, $this->log->selects());
        self::assertSame(10, $items[0]->relation('category')->value('id'));
        self::assertSame('One', $items[0]->relation('profile')->value('label'));
        self::assertSame([22, 21], self::ids($items[0]->relation('notes')));
        self::assertSame([102, 103, 101], self::ids($items[0]->relation('tags')));
        self::assertNull($items[2]->relation('category'));
        self::assertNull($items[3]->relation('category'));
        self::assertNull($items[3]->relation('profile'));
        self::assertSame([], $items[3]->relation('notes'));
        self::assertSame([], $items[3]->relation('tags'));
        self::assertObjectNotHasProperty('id', $items[0]);
    }

    public function testCatalogUsesFourSelectsAndLimitsOwnersBeforeLoadingCollections(): void
    {
        $items = $this->items->withLocale('uk', 'en')->with([
            'category',
            new Load('tags', criteria: ['name IN' => ['Zulu', 'Beta UK']], orderBy: ['name' => 'ASC']),
        ])->findBy(orderBy: ['id' => 'ASC'], limit: 2);

        self::assertCount(4, $this->log->selects());
        self::assertSame([1, 2], self::ids($items));
        self::assertSame([102, 101], self::ids($items[0]->relation('tags')));
        self::assertSame([102], self::ids($items[1]->relation('tags')));
        self::assertSame('Zulu', $items[0]->relation('tags')[1]->value('name'));
        self::assertSame('Category UK', $items[1]->relation('category')->value('name'));
        self::assertStringContainsString('LIMIT 2', $this->log->selects()[0]['sql']);
    }

    public function testNestedLoadsAndNestedExistsShareInheritedLocaleAndFallback(): void
    {
        $query = $this->items->withLocale('uk', 'en')->with(['category.parent']);
        $criteria = Filter::related('category', Filter::related('parent', ['name' => 'Parent EN']));
        $items = $query->findBy($criteria, ['id' => 'ASC']);

        self::assertSame([1, 2], self::ids($items));
        self::assertCount(3, $this->log->selects());
        self::assertSame('Item 1 UK', $items[0]->value('name'));
        self::assertSame('Category UK', $items[0]->relation('category')->value('name'));
        self::assertSame('Parent EN', $items[0]->relation('category')->relation('parent')->value('name'));

        $this->log->reset();
        $default = $this->items->with(['category.parent'])->find(1);
        self::assertSame('Category EN', $default->relation('category')->value('name'));
        self::assertSame('Parent EN', $default->relation('category')->relation('parent')->value('name'));
        self::assertCount(3, $this->log->selects());
    }

    public function testRelatedSameRowGroupingCountAndPagination(): void
    {
        self::assertSame([], $this->items->read()->rows(['id'], Filter::related('notes', [
            'body' => 'zeta', 'enabled' => true,
        ])));
        self::assertSame([['id' => 1]], $this->items->read()->rows(['id'], Filter::all(
            Filter::related('notes', ['body' => 'zeta']),
            Filter::related('notes', ['enabled' => true]),
        )));
        self::assertSame([['id' => 4]], $this->items->read()->rows(['id'], Filter::not(
            Filter::related('notes', ['enabled' => true]),
        )));
        $criteria = Filter::all(
            Filter::any(['id' => 4], Filter::related('tags', ['enabled' => true])),
            Filter::not(['sku' => 'missing']),
        );
        $query = $this->items->with(['category', 'tags']);
        $this->log->reset();
        self::assertSame(3, $query->count($criteria));
        self::assertTrue($query->exists($criteria));
        self::assertCount(2, $this->log->selects());
        self::assertSame([2], self::ids($query->findBy($criteria, ['id' => 'ASC'], 1, 1)));
        self::assertSame([4], self::ids($query->findBy($criteria, ['id' => 'ASC'], 1, 2)));
        self::assertSame([], $query->findBy($criteria, ['id' => 'ASC'], 1, 3));
    }

    public function testAllRelatedKindsRespectIndependentVisibilityAndLoadingScope(): void
    {
        $filters = [
            Filter::related('category', ['id' => 10]),
            Filter::related('profile', ['label IN' => ['One', 'Two']]),
            Filter::related('notes', ['enabled' => true, 'body !=' => 'different']),
            Filter::related('tags', ['enabled' => true]),
        ];
        foreach ($filters as $criteria) {
            self::assertSame([['id' => 1], ['id' => 2]], $this->items->read()->rows(
                ['id'],
                $criteria,
                ['id' => 'ASC'],
            ));
        }
        self::assertSame([], $this->items->read()->rows(['id'], Filter::related('tags', Filter::any(
            ['slug' => 'absent'],
            ['deleted_at !=' => null],
        ))));
        self::assertSame([['id' => 1]], $this->items->read()->rows(['id'], Filter::related(
            'tags',
            visibility: Visibility::Deleted,
        )));
        self::assertSame([['id' => 5]], $this->items->read()->withVisibility(Visibility::Deleted)->rows(
            ['id'],
            Filter::related('tags', ['slug' => 'first']),
        ));
        $items = $this->items->with([new Load('tags', orderBy: ['id' => 'ASC'])])->findBy(
            Filter::related('tags', ['slug' => 'first']),
        );
        self::assertSame([1], self::ids($items));
        self::assertSame([101, 102, 103], self::ids($items[0]->relation('tags')));
        $hidden = $this->items->with([new Load('tags', visibility: Visibility::Deleted)])->find(1);
        self::assertSame([104], self::ids($hidden->relation('tags')));
    }

    public function testOwnerBatchesReservePredicateParameters(): void
    {
        $items = [];
        $notes = [];
        foreach (range(1000, 1901) as $id) {
            $items[] = [$id, null, 'batch-' . $id, null];
            $notes[] = [$id, $id, 'note-' . $id, true, null];
        }
        $this->insertRows('repo_rel_items', $this->itemTypes(), $items);
        $this->insertRows('repo_rel_notes', $this->noteTypes(), $notes);
        $this->log->reset();

        $models = $this->items->with([new Load('notes', criteria: ['enabled' => true])])->findBy(
            ['id >=' => 1000],
            ['id' => 'ASC'],
        );

        self::assertCount(902, $models);
        self::assertCount(3, $this->log->selects());
        self::assertCount(900, $this->log->selects()[1]['params']);
        self::assertCount(4, $this->log->selects()[2]['params']);
        foreach ($models as $model) {
            self::assertSame([$model->value('id')], self::ids($model->relation('notes')));
        }
    }

    public function testManyToManyFanoutExceedsParameterLimitWithoutSplittingTargetOrder(): void
    {
        $tags = [];
        $translations = [];
        $links = [];
        foreach (range(1000, 1904) as $id) {
            $tags[] = [$id, 'fanout-' . $id, true, null];
            $translations[] = [$id, 'en', sprintf('Rank %04d', 1904 - $id)];
            $links[] = [4, $id];
        }
        $this->insertRows('repo_rel_tags', $this->tagTypes(), $tags);
        $this->insertRows('repo_rel_tag_translations', [
            'tag_id' => Types::INTEGER, 'locale' => Types::STRING, 'name' => Types::STRING,
        ], $translations);
        $this->insertRows('repo_rel_item_tags', ['item_id' => Types::INTEGER, 'tag_id' => Types::INTEGER], $links);
        $this->log->reset();

        $item = $this->items->withLocale('uk', 'en')->with([
            new Load('tags', orderBy: ['name' => 'ASC']),
        ])->find(4);

        self::assertSame(range(1904, 1000), self::ids($item->relation('tags')));
        self::assertCount(3, $this->log->selects());
        foreach ($this->log->selects() as $query) {
            self::assertLessThanOrEqual(900, count($query['params']));
        }
        self::assertSame('Rank 0000', $item->relation('tags')[0]->value('name'));
        self::assertSame('Rank 0904', $item->relation('tags')[904]->value('name'));
    }

    public function testKeysetReleasesPreviousPageAndItsRelatedObjects(): void
    {
        $iterator = $this->items->with(['category', 'tags'])->iterateById(batchSize: 2);
        $first = $iterator->current();
        $owner = WeakReference::create($first);
        $category = WeakReference::create($first->relation('category'));
        $tag = WeakReference::create($first->relation('tags')[0]);
        self::assertSame(1, $first->value('id'));
        unset($first);
        $iterator->next();
        self::assertSame(2, $iterator->current()->value('id'));
        $iterator->next();
        self::assertSame(3, $iterator->current()->value('id'));
        self::assertNull($owner->get());
        self::assertNull($category->get());
        self::assertNull($tag->get());
        $iterator->next();
        self::assertSame(4, $iterator->current()->value('id'));
        $iterator->next();
        self::assertFalse($iterator->valid());
        self::assertCount(9, $this->log->selects());
        foreach ($this->log->selects() as $query) {
            self::assertStringNotContainsString('OFFSET', $query['sql']);
            self::assertStringNotContainsString('COUNT(', $query['sql']);
        }
    }

    public function testLinkSetRepeatedWritesUsePhysicalMembershipIncludingDeletedRows(): void
    {
        $links = $this->items->links('tags');
        $links->attach('1', [101, '101', 104]);
        $links->attach(1, [104]);
        self::assertSame([101, 102, 103, 104], $this->linkedIds(1));
        $links->detach(1, [101, '101', 999]);
        $links->detach(1, [101]);
        self::assertSame([102, 103, 104], $this->linkedIds(1));
        $links->sync(1, [102, 104, '104']);
        $this->log->reset();
        $links->sync(1, [104, 102]);
        self::assertSame([], $this->mutations());
        self::assertSame([102, 104], $this->linkedIds(1));
        $links->attach(5, [104]);
        self::assertSame([101, 104], $this->linkedIds(5));
        $links->sync(1, []);
        self::assertSame([], $this->linkedIds(1));
        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testLateForeignKeyFailureRollsBackSyncAndPreservesOuterTransaction(): void
    {
        $rows = [];
        foreach (range(1000, 1450) as $id) {
            $rows[] = [$id, 'rollback-' . $id, true, null];
        }
        $this->insertRows('repo_rel_tags', $this->tagTypes(), $rows);
        $this->connection->beginTransaction();
        $this->connection->update('repo_rel_items', ['sku' => 'outer survives'], ['id' => 1]);
        $this->log->reset();

        try {
            $this->items->links('tags')->sync(1, [...range(1000, 1450), 999999]);
            self::fail('The missing target must fail the second INSERT batch.');
        } catch (ForeignKeyConstraintViolationException) {
            $inserts = array_values(array_filter(
                $this->log->queries,
                static fn(array $query): bool => str_starts_with($query['sql'], 'INSERT INTO '),
            ));
            self::assertCount(2, $inserts);
            self::assertCount(900, $inserts[0]['params']);
            self::assertCount(4, $inserts[1]['params']);
        }

        self::assertTrue($this->connection->isTransactionActive());
        self::assertSame(1, $this->connection->getTransactionNestingLevel());
        self::assertSame([101, 102, 103, 104], $this->linkedIds(1));
        self::assertSame('outer survives', $this->items->read()->find(1)->value('sku'));
        $this->connection->commit();
        self::assertSame('outer survives', $this->items->read()->find(1)->value('sku'));
        self::assertSame([101, 102, 103, 104], $this->linkedIds(1));
    }

    public function testStringKeysPreserveNumericStringsAndUseDatabaseCaseRules(): void
    {
        $owners = $this->keyRepositories('string', Types::STRING);
        $mysql = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $ownerArgument = $mysql ? 'owner-a' : 'Owner-A';
        $targetArgument = $mysql ? 'tag-x' : 'Tag-X';
        $this->insertRows('repo_rel_string_owners', ['id' => Types::STRING], [['Owner-A']]);
        $this->insertRows('repo_rel_string_targets', ['id' => Types::STRING], [['0'], ['01'], ['1'], ['Tag-X']]);
        $this->insertRows('repo_rel_string_links', ['owner_id' => Types::STRING, 'target_id' => Types::STRING], [
            [$mysql ? 'OWNER-A' : 'Owner-A', $mysql ? 'TAG-X' : 'Tag-X'],
        ]);
        $links = $owners->links('targets');
        $links->attach($ownerArgument, [$targetArgument, '0', 0, '01', '1']);
        $this->log->reset();
        $links->attach($ownerArgument, [$targetArgument, '01']);
        self::assertSame([], $this->mutations());
        self::assertSame(['0', '01', '1', 'Tag-X'], self::ids($owners->with([
            new Load('targets', orderBy: ['id' => 'ASC']),
        ])->find($ownerArgument)->relation('targets')));

        $links->detach($ownerArgument, [$mysql ? 'TAG-X' : 'Tag-X', '01']);
        self::assertSame(['0', '1'], self::ids($owners->with([
            new Load('targets', orderBy: ['id' => 'ASC']),
        ])->find($ownerArgument)->relation('targets')));
        $links->sync($ownerArgument, [$targetArgument, '01']);
        $links->sync($ownerArgument, ['01', $targetArgument]);
        self::assertSame(['01', 'Tag-X'], self::ids($owners->with([
            new Load('targets', orderBy: ['id' => 'ASC']),
        ])->find($ownerArgument)->relation('targets')));
        self::assertSame([['id' => 'Owner-A']], $owners->read()->rows(
            ['id'],
            Filter::related('targets', ['id' => $targetArgument]),
        ));
    }

    public function testGuidUnionCanonicalizationBatchesAndDeduplicatesUppercaseIds(): void
    {
        $owners = $this->keyRepositories('guid', Types::GUID);
        $owner = 'bbbbbbbb-aaaa-4ccc-8ddd-000000000001';
        $ids = array_map(static fn(int $id): string => sprintf('aaaaaaaa-bbbb-4ccc-8ddd-%012x', $id), range(1, 451));
        $this->insertRows('repo_rel_guid_owners', ['id' => Types::GUID], [[$owner]]);
        $this->insertRows('repo_rel_guid_targets', ['id' => Types::GUID], array_map(
            static fn(string $id): array => [$id],
            $ids,
        ));
        $this->insertRows('repo_rel_guid_links', ['owner_id' => Types::GUID, 'target_id' => Types::GUID], [
            [strtoupper($owner), strtoupper($ids[0])],
        ]);
        $links = $owners->links('targets');
        $this->log->reset();
        $links->attach(strtoupper($owner), [...array_map('strtoupper', $ids), $ids[0]]);
        $canonicalQueries = array_values(array_filter(
            $this->log->selects(),
            static fn(array $query): bool => str_contains($query['sql'], 'UNION ALL SELECT'),
        ));
        self::assertCount(2, $canonicalQueries);
        foreach ($this->log->queries as $query) {
            self::assertLessThanOrEqual(900, count($query['params']));
        }
        self::assertSame($ids, self::ids($owners->with([
            new Load('targets', orderBy: ['id' => 'ASC']),
        ])->find(strtoupper($owner))->relation('targets')));
        $this->log->reset();
        $links->attach($owner, [$ids[0], strtoupper($ids[0])]);
        self::assertSame([], $this->mutations());
        $links->sync(strtoupper($owner), [strtoupper($ids[449]), strtoupper($ids[450])]);
        $links->detach(strtoupper($owner), [strtoupper($ids[449])]);
        self::assertSame([$ids[450]], self::ids($owners->with(['targets'])->find($owner)->relation('targets')));
        self::assertSame([['id' => $owner]], $owners->read()->rows(
            ['id'],
            Filter::related('targets', ['id' => strtoupper($ids[450])]),
        ));
    }

    private function createFixtureSchema(): void
    {
        $schema = new Schema();
        $categories = $schema->createTable('repo_rel_categories');
        $categories->addColumn('id', Types::INTEGER);
        $categories->addColumn('parent_id', Types::INTEGER, ['notnull' => false]);
        $categories->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $categories->setPrimaryKey(['id']);
        $categories->addForeignKeyConstraint('repo_rel_categories', ['parent_id'], ['id']);
        $items = $schema->createTable('repo_rel_items');
        $items->addColumn('id', Types::INTEGER);
        $items->addColumn('category_id', Types::INTEGER, ['notnull' => false]);
        $items->addColumn('sku', Types::STRING, ['length' => 100]);
        $items->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $items->setPrimaryKey(['id']);
        $items->addForeignKeyConstraint('repo_rel_categories', ['category_id'], ['id']);
        $profiles = $schema->createTable('repo_rel_profiles');
        $profiles->addColumn('id', Types::INTEGER);
        $profiles->addColumn('item_id', Types::INTEGER);
        $profiles->addColumn('label', Types::STRING, ['length' => 100]);
        $profiles->setPrimaryKey(['id']);
        $profiles->addUniqueIndex(['item_id']);
        $profiles->addForeignKeyConstraint('repo_rel_items', ['item_id'], ['id']);
        $notes = $schema->createTable('repo_rel_notes');
        $notes->addColumn('id', Types::INTEGER);
        $notes->addColumn('item_id', Types::INTEGER);
        $notes->addColumn('body', Types::STRING, ['length' => 100]);
        $notes->addColumn('enabled', Types::BOOLEAN);
        $notes->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $notes->setPrimaryKey(['id']);
        $notes->addForeignKeyConstraint('repo_rel_items', ['item_id'], ['id']);
        $tags = $schema->createTable('repo_rel_tags');
        $tags->addColumn('id', Types::INTEGER);
        $tags->addColumn('slug', Types::STRING, ['length' => 100]);
        $tags->addColumn('enabled', Types::BOOLEAN);
        $tags->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $tags->setPrimaryKey(['id']);
        foreach (['category' => 'categories', 'item' => 'items', 'tag' => 'tags'] as $singular => $plural) {
            $translations = $schema->createTable('repo_rel_' . $singular . '_translations');
            $translations->addColumn($singular . '_id', Types::INTEGER);
            $translations->addColumn('locale', Types::STRING, ['length' => 10]);
            $translations->addColumn('name', Types::STRING, ['length' => 100, 'notnull' => false]);
            $translations->setPrimaryKey([$singular . '_id', 'locale']);
            $translations->addForeignKeyConstraint('repo_rel_' . $plural, [$singular . '_id'], ['id']);
        }
        $pivot = $schema->createTable('repo_rel_item_tags');
        $pivot->addColumn('item_id', Types::INTEGER);
        $pivot->addColumn('tag_id', Types::INTEGER);
        $pivot->setPrimaryKey(['item_id', 'tag_id']);
        $pivot->addForeignKeyConstraint('repo_rel_items', ['item_id'], ['id']);
        $pivot->addForeignKeyConstraint('repo_rel_tags', ['tag_id'], ['id']);
        $this->createSchema($schema);
    }

    private function seed(): void
    {
        $this->insertRows('repo_rel_categories', [
            'id' => Types::INTEGER, 'parent_id' => Types::INTEGER, 'deleted_at' => Types::STRING,
        ], [[100, null, null], [10, 100, null], [20, 100, '2026-01-01 00:00:00']]);
        $this->insertRows('repo_rel_category_translations', [
            'category_id' => Types::INTEGER, 'locale' => Types::STRING, 'name' => Types::STRING,
        ], [[100, 'en', 'Parent EN'], [100, 'fr', 'Parent FR'], [10, 'en', 'Category EN'],
            [10, 'uk', 'Category UK'], [20, 'en', 'Hidden category']]);
        $this->insertRows('repo_rel_items', $this->itemTypes(), [
            [1, 10, 'SKU-1', null], [2, 10, 'SKU-2', null], [3, 20, 'SKU-3', null],
            [4, null, 'SKU-4', null], [5, 10, 'SKU-5', '2026-01-01 00:00:00'],
        ]);
        $translations = [];
        foreach (range(1, 5) as $id) {
            $translations[] = [$id, 'en', 'Item ' . $id . ' EN'];
            $translations[] = [$id, 'uk', 'Item ' . $id . ' UK'];
        }
        $this->insertRows('repo_rel_item_translations', [
            'item_id' => Types::INTEGER, 'locale' => Types::STRING, 'name' => Types::STRING,
        ], $translations);
        $this->insertRows('repo_rel_profiles', [
            'id' => Types::INTEGER, 'item_id' => Types::INTEGER, 'label' => Types::STRING,
        ], [[1, 1, 'One'], [2, 2, 'Two']]);
        $this->insertRows('repo_rel_notes', $this->noteTypes(), [
            [21, 1, 'zeta', false, null], [22, 1, 'alpha', true, null],
            [23, 2, 'beta', true, '2026-01-01 00:00:00'], [24, 2, 'gamma', true, null],
            [25, 3, 'different', true, null],
        ]);
        $this->insertRows('repo_rel_tags', $this->tagTypes(), [
            [101, 'first', true, null], [102, 'second', true, null], [103, 'disabled', false, null],
            [104, 'hidden', true, '2026-01-01 00:00:00'],
        ]);
        $this->insertRows('repo_rel_tag_translations', [
            'tag_id' => Types::INTEGER, 'locale' => Types::STRING, 'name' => Types::STRING,
        ], [[101, 'en', 'Zulu'], [102, 'en', 'Alpha'], [103, 'en', 'Bravo'], [104, 'en', 'Hidden'],
            [101, 'uk', null], [102, 'uk', 'Beta UK'], [103, 'uk', 'Zero UK'], [104, 'uk', 'Hidden UK']]);
        $this->insertRows('repo_rel_item_tags', ['item_id' => Types::INTEGER, 'tag_id' => Types::INTEGER], [
            [1, 101], [1, 102], [1, 103], [1, 104], [2, 102], [3, 103], [5, 101],
        ]);
    }

    private function createRepositories(): void
    {
        $parents = new Repository($this->connection, $this->categoryMapping(defaultLocale: 'fr'));
        $this->categories = new Repository($this->connection, $this->categoryMapping([
            'parent' => new BelongsTo(static fn(): Repository => $parents, 'parent_id', self::assign('parent')),
        ], 'uk'));
        $this->profiles = new Repository($this->connection, new Mapping(
            'repo_rel_profiles',
            ['id' => Types::INTEGER, 'item_id' => Types::INTEGER, 'label' => Types::STRING],
            self::model(...),
            generatedId: false,
        ));
        $this->notes = new Repository($this->connection, new Mapping(
            'repo_rel_notes',
            array_replace($this->noteTypes(), ['deleted_at' => Types::DATETIME_IMMUTABLE]),
            self::model(...),
            generatedId: false,
            softDeleteColumn: 'deleted_at',
        ));
        $this->tags = new Repository($this->connection, new Mapping(
            'repo_rel_tags',
            array_replace($this->tagTypes(), ['deleted_at' => Types::DATETIME_IMMUTABLE]),
            self::model(...),
            generatedId: false,
            softDeleteColumn: 'deleted_at',
            translations: new Translations('repo_rel_tag_translations', 'tag_id', ['name' => Types::STRING], 'en'),
        ));
        $this->items = new Repository($this->connection, new Mapping(
            'repo_rel_items',
            array_replace($this->itemTypes(), ['deleted_at' => Types::DATETIME_IMMUTABLE]),
            self::model(...),
            generatedId: false,
            softDeleteColumn: 'deleted_at',
            translations: new Translations('repo_rel_item_translations', 'item_id', ['name' => Types::STRING], 'en'),
            relations: [
                'category' => new BelongsTo(
                    fn(): Repository => $this->categories,
                    'category_id',
                    self::assign('category'),
                ),
                'profile' => new HasOne(fn(): Repository => $this->profiles, 'item_id', self::assign('profile')),
                'notes' => new HasMany(fn(): Repository => $this->notes, 'item_id', self::assign('notes')),
                'tags' => new BelongsToMany(
                    fn(): Repository => $this->tags,
                    'repo_rel_item_tags',
                    'item_id',
                    'tag_id',
                    self::assign('tags'),
                ),
            ],
        ));
    }

    private function categoryMapping(array $relations = [], string $defaultLocale = 'en'): Mapping
    {
        return new Mapping(
            'repo_rel_categories',
            ['id' => Types::INTEGER, 'parent_id' => Types::INTEGER, 'deleted_at' => Types::DATETIME_IMMUTABLE],
            self::model(...),
            generatedId: false,
            softDeleteColumn: 'deleted_at',
            translations: new Translations(
                'repo_rel_category_translations',
                'category_id',
                ['name' => Types::STRING],
                $defaultLocale,
            ),
            relations: $relations,
        );
    }

    private function keyRepositories(string $prefix, string $type): Repository
    {
        $schema = new Schema();
        $names = [];
        foreach (['owners', 'targets', 'links'] as $suffix) {
            $names[$suffix] = 'repo_rel_' . $prefix . '_' . $suffix;
        }
        $options = $type === Types::STRING ? ['length' => 64] : [];
        foreach (['owners', 'targets'] as $suffix) {
            $table = $schema->createTable($names[$suffix]);
            $table->addColumn('id', $type, $options);
            $table->setPrimaryKey(['id']);
        }
        $pivot = $schema->createTable($names['links']);
        $pivot->addColumn('owner_id', $type, $options);
        $pivot->addColumn('target_id', $type, $options);
        $pivot->setPrimaryKey(['owner_id', 'target_id']);
        $pivot->addForeignKeyConstraint($names['owners'], ['owner_id'], ['id']);
        $pivot->addForeignKeyConstraint($names['targets'], ['target_id'], ['id']);
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            foreach ($schema->getTables() as $table) {
                $table->addOption('charset', 'utf8mb4');
                $table->addOption('collation', 'utf8mb4_unicode_ci');
            }
        }
        $this->createSchema($schema);
        $targets = new Repository($this->connection, new Mapping(
            $names['targets'],
            ['id' => $type],
            self::model(...),
            generatedId: false,
        ));
        return new Repository($this->connection, new Mapping(
            $names['owners'],
            ['id' => $type],
            self::model(...),
            generatedId: false,
            relations: ['targets' => new BelongsToMany(
                static fn(): Repository => $targets,
                $names['links'],
                'owner_id',
                'target_id',
                self::assign('targets'),
            )],
        ));
    }

    /** @param array<string, string> $columns @param list<list<mixed>> $rows */
    private function insertRows(string $table, array $columns, array $rows): void
    {
        $quote = $this->connection->quoteIdentifier(...);
        $tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        foreach (array_chunk($rows, intdiv(900, count($columns))) as $chunk) {
            $parameters = [];
            $types = [];
            foreach ($chunk as $row) {
                array_push($parameters, ...$row);
                array_push($types, ...array_values($columns));
            }
            $this->connection->executeStatement(
                'INSERT INTO ' . $quote($table) . ' (' . implode(', ', array_map($quote, array_keys($columns)))
                    . ') VALUES ' . implode(', ', array_fill(0, count($chunk), $tuple)),
                $parameters,
                $types,
            );
        }
    }

    private function itemTypes(): array
    {
        return ['id' => Types::INTEGER, 'category_id' => Types::INTEGER,
            'sku' => Types::STRING, 'deleted_at' => Types::STRING];
    }

    private function noteTypes(): array
    {
        return ['id' => Types::INTEGER, 'item_id' => Types::INTEGER, 'body' => Types::STRING,
            'enabled' => Types::BOOLEAN, 'deleted_at' => Types::STRING];
    }

    private function tagTypes(): array
    {
        return ['id' => Types::INTEGER, 'slug' => Types::STRING,
            'enabled' => Types::BOOLEAN, 'deleted_at' => Types::STRING];
    }

    private function linkedIds(int $owner): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT tag_id FROM repo_rel_item_tags WHERE item_id = ? ORDER BY tag_id',
            [$owner],
            [Types::INTEGER],
        ));
    }

    private function mutations(): array
    {
        return array_values(array_filter(
            $this->log->statements(),
            static fn(string $sql): bool => preg_match('/^(INSERT|UPDATE|DELETE) /', $sql) === 1,
        ));
    }

    private static function model(array $row): object
    {
        return new class ($row) {
            private array $relations = [];

            public function __construct(private readonly array $row)
            {
            }

            public function value(string $name): mixed
            {
                return $this->row[$name];
            }

            public function relation(string $name): mixed
            {
                return $this->relations[$name];
            }

            public function with(string $name, mixed $value): self
            {
                $copy = clone $this;
                $copy->relations[$name] = $value;
                return $copy;
            }
        };
    }

    private static function assign(string $name): Closure
    {
        return static fn(object $owner, mixed $value): object => $owner->with($name, $value);
    }

    private static function ids(array $models): array
    {
        return array_map(static fn(object $model): int|string => $model->value('id'), $models);
    }
}
