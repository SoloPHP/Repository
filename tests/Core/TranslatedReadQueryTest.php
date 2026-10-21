<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Closure;
use DateTimeImmutable;
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
use RuntimeException;
use Solo\Repository\Filter;
use Solo\Repository\Mapping;
use Solo\Repository\ReadQuery;
use Solo\Repository\Repository;
use Solo\Repository\Translations;
use Solo\Repository\Visibility;
use Stringable;

final class TranslatedReadQueryTest extends TestCase
{
    private Connection $connection;
    private AbstractLogger $logger;
    private Repository $repository;

    protected function setUp(): void
    {
        $this->logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $queries = [];

            /** @var list<array<array-key, mixed>> */
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
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->connection->executeStatement(
            'CREATE TABLE items ('
            . 'id INTEGER PRIMARY KEY, sku TEXT NOT NULL, enabled BOOLEAN NOT NULL, '
            . 'created_at DATETIME NOT NULL, deleted_at DATETIME, unmapped TEXT)'
        );
        $this->connection->executeStatement(
            'CREATE TABLE item_translations ('
            . 'item_id INTEGER NOT NULL REFERENCES items(id), locale TEXT NOT NULL, '
            . 'name TEXT, description TEXT, UNIQUE(item_id, locale))'
        );
        foreach (range(1, 10) as $id) {
            $this->connection->insert('items', [
                'id' => $id,
                'sku' => 'SKU-' . $id,
                'enabled' => $id === 2 ? 0 : 1,
                'created_at' => '2026-01-02 03:04:05',
                'deleted_at' => $id === 9 ? '2026-02-03 04:05:06' : null,
                'unmapped' => 'not selected',
            ]);
        }
        $translations = [
            [1, 'en', 'Bravo', 'English one'],
            [1, 'uk', null, 'Ukrainian one'],
            [2, 'en', 'Alpha', 'English two'],
            [2, 'uk', 'Zulu', null],
            [3, 'en', 'Charlie', 'English three'],
            [4, 'en', 'Delta', 'English four'],
            [4, 'uk', '', ''],
            [5, 'en', 'Echo', 'English five'],
            [5, 'uk', '0', '0'],
            [6, 'en', 'Foxtrot', 'English six'],
            [6, 'uk', '   ', '  '],
            [7, 'en', null, null],
            [7, 'uk', null, null],
            [9, 'en', 'Hidden EN', 'Deleted English'],
            [9, 'uk', 'Hidden UK', 'Deleted Ukrainian'],
            [10, 'uk', 'Solo', 'Only Ukrainian'],
        ];
        foreach ($translations as [$id, $locale, $name, $description]) {
            $this->connection->insert('item_translations', [
                'item_id' => $id,
                'locale' => $locale,
                'name' => $name,
                'description' => $description,
            ]);
        }
        $this->repository = new Repository($this->connection, $this->mapping(), parameterLimit: 900);
        $this->resetLog();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testBareReadUsesOnlyTheDefaultLocale(): void
    {
        self::assertSame('Alpha', $this->repository->read()->find(2)->name);
        self::assertNull($this->repository->read()->find(10)->name);
        self::assertCount(2, $this->logger->queries);
        foreach ($this->logger->queries as $sql) {
            self::assertSame(1, $this->joinCount($sql));
        }
    }

    public function testFallbackIsResolvedIndependentlyForEachField(): void
    {
        $query = $this->repository->withLocale('uk', fallback: 'en');

        self::assertSame(
            [
                ['id' => 1, 'name' => 'Bravo', 'description' => 'Ukrainian one'],
                ['id' => 2, 'name' => 'Zulu', 'description' => 'English two'],
                ['id' => 3, 'name' => 'Charlie', 'description' => 'English three'],
            ],
            $query->rows(['id', 'name', 'description'], ['id IN' => [1, 2, 3]], ['id' => 'ASC']),
        );
        self::assertCount(1, $this->logger->queries);
        self::assertSame(2, $this->joinCount($this->logger->queries[0]));
    }

    public function testEmptyZeroAndWhitespaceTranslationsDoNotFallBack(): void
    {
        self::assertSame(
            [
                ['id' => 4, 'name' => '', 'description' => ''],
                ['id' => 5, 'name' => '0', 'description' => '0'],
                ['id' => 6, 'name' => '   ', 'description' => '  '],
            ],
            $this->repository->withLocale('uk', 'en')->rows(
                ['id', 'name', 'description'],
                ['id IN' => [4, 5, 6]],
                ['id' => 'ASC'],
            ),
        );
    }

    public function testMissingAndAllNullTranslationsPreserveOwnersWithNullFields(): void
    {
        self::assertSame(
            [
                ['id' => 7, 'name' => null, 'description' => null],
                ['id' => 8, 'name' => null, 'description' => null],
                ['id' => 10, 'name' => 'Solo', 'description' => 'Only Ukrainian'],
            ],
            $this->repository->withLocale('uk', 'en')->rows(
                ['id', 'name', 'description'],
                ['id IN' => [7, 8, 10]],
                ['id' => 'ASC'],
            ),
        );
    }

    public function testExplicitNoFallbackAndEqualFallbackUseOneLocaleJoin(): void
    {
        foreach ([null, 'uk'] as $fallback) {
            $this->resetLog();
            $item = $this->repository->read()->withLocale('uk', $fallback)->find(1);
            self::assertNull($item->name);
            self::assertSame('Ukrainian one', $item->description);
            self::assertSame(1, $this->joinCount($this->logger->queries[0]));
            self::assertSame(1, count(array_filter(
                $this->logger->parameters[0],
                static fn(mixed $value): bool => $value === 'uk',
            )));
        }
    }

    public function testHydratorReceivesAllMappedTypedAndTranslatedFields(): void
    {
        $item = $this->repository->withLocale('uk', 'en')->find(2);

        self::assertSame(
            ['id', 'sku', 'enabled', 'created_at', 'deleted_at', 'name', 'description'],
            array_keys(get_object_vars($item)),
        );
        self::assertSame(2, $item->id);
        self::assertFalse($item->enabled);
        self::assertInstanceOf(DateTimeImmutable::class, $item->created_at);
        self::assertSame('Zulu', $item->name);
        self::assertSame('English two', $item->description);
    }

    public function testPartialProjectionDoesNotHydrateOrAddOtherFields(): void
    {
        $mapping = $this->mapping(static function (): object {
            throw new RuntimeException('Partial rows must not hydrate.');
        });
        $repository = new Repository($this->connection, $mapping);

        self::assertSame(
            [['id' => 1, 'name' => 'Bravo']],
            $repository->withLocale('uk', 'en')->rows(['id', 'name'], ['id' => 1]),
        );
        self::assertStringNotContainsString('description', $this->logger->queries[0]);
        self::assertSame(2, $this->joinCount($this->logger->queries[0]));
    }

    #[DataProvider('translatedOperators')]
    public function testAllFilterOperatorsUseTheDisplayedResolvedValue(
        string $condition,
        mixed $value,
        array $expectedIds,
    ): void {
        $query = $this->repository->withLocale('uk', 'en');
        $criteria = Filter::all(['id IN' => [1, 2, 3, 7, 8]], [$condition => $value]);
        $rows = $query->rows(['id', 'name'], $criteria, ['id' => 'ASC']);

        self::assertSame($expectedIds, array_column($rows, 'id'));
        $names = [1 => 'Bravo', 2 => 'Zulu', 3 => 'Charlie', 7 => null, 8 => null];
        foreach ($rows as $row) {
            self::assertSame($names[$row['id']], $row['name']);
        }
        self::assertSame(count($expectedIds), $query->count($criteria));
        self::assertSame($expectedIds !== [], $query->exists($criteria));
        self::assertCount(3, $this->logger->queries);
        foreach ($this->logger->queries as $sql) {
            self::assertSame(2, $this->joinCount($sql));
        }
    }

    public static function translatedOperators(): array
    {
        return [
            'equal' => ['name', 'Bravo', [1]],
            'not equal' => ['name !=', 'Bravo', [2, 3]],
            'greater' => ['name >', 'Bravo', [2, 3]],
            'greater or equal' => ['name >=', 'Charlie', [2, 3]],
            'less' => ['name <', 'Charlie', [1]],
            'less or equal' => ['name <=', 'Charlie', [1, 3]],
            'in' => ['name IN', ['Bravo', 'Charlie'], [1, 3]],
            'not in' => ['name NOT IN', ['Bravo', 'Charlie'], [2]],
            'like' => ['name LIKE', 'Br%', [1]],
            'not like' => ['name NOT LIKE', 'Br%', [2, 3]],
            'null' => ['name', null, [7, 8]],
            'not null' => ['name !=', null, [1, 2, 3]],
        ];
    }

    public function testLogicalGroupsKeepFallbackAndSoftDeleteSemantics(): void
    {
        $criteria = Filter::all(
            Filter::any(['name' => 'Bravo'], ['name LIKE' => 'Hidden%'], ['name' => 'Zulu']),
            Filter::not(['name' => 'Zulu']),
        );
        $query = $this->repository->withLocale('uk', 'en');

        self::assertSame([1], array_column($query->rows(['id'], $criteria), 'id'));
        self::assertSame(
            [1, 9],
            array_column($query->withVisibility(Visibility::All)->rows(['id'], $criteria, ['id' => 'ASC']), 'id'),
        );
        self::assertSame(
            [3],
            array_column($query->rows(['id'], Filter::all(
                ['id IN' => [1, 3, 7, 8]],
                Filter::not(['name' => 'Bravo']),
            )), 'id'),
        );
        self::assertSame([], $query->rows(['id'], ['name IN' => []]));
    }

    public function testSortingUsesResolvedValuesEvenWithBaseOnlyProjection(): void
    {
        $query = $this->repository->withLocale('uk', 'en');
        $criteria = ['id IN' => [1, 2, 3]];

        self::assertSame(
            [
                ['id' => 1, 'name' => 'Bravo'],
                ['id' => 3, 'name' => 'Charlie'],
                ['id' => 2, 'name' => 'Zulu'],
            ],
            $query->rows(['id', 'name'], $criteria, ['name' => 'ASC', 'id' => 'ASC']),
        );
        self::assertSame(
            [['id' => 3]],
            $query->rows(['id'], $criteria, ['name' => 'ASC', 'id' => 'ASC'], 1, 1),
        );
        self::assertSame(2, $this->joinCount($this->logger->queries[1]));
    }

    public function testBaseProjectionCountAndExistsNeedNoTranslationJoins(): void
    {
        $query = $this->repository->withLocale('uk', 'en');

        self::assertSame([['id' => 1, 'sku' => 'SKU-1']], $query->rows(['id', 'sku'], ['id' => 1]));
        self::assertSame(8, $query->count(['enabled' => true]));
        self::assertTrue($query->exists(['id' => 1]));
        self::assertCount(3, $this->logger->queries);
        foreach ($this->logger->queries as $index => $sql) {
            self::assertSame(0, $this->joinCount($sql));
            self::assertNotContains('uk', $this->logger->parameters[$index]);
            self::assertNotContains('en', $this->logger->parameters[$index]);
        }
    }

    public function testLocaleAndVisibilityBranchesPreserveEachOtherAndRemainReusable(): void
    {
        $default = $this->repository->read();
        $uk = $default->withLocale('uk', 'en');
        $deleted = $uk->withVisibility(Visibility::Deleted);
        $deletedEnglish = $deleted->withLocale('en');

        self::assertSame('Zulu', $uk->find(2)->name);
        self::assertSame('Hidden UK', $deleted->find(9)->name);
        self::assertSame('Hidden EN', $deletedEnglish->find(9)->name);
        self::assertSame('Alpha', $default->find(2)->name);
        self::assertNull($uk->find(9));
        self::assertSame('Zulu', $uk->find(2)->name);
    }

    public function testLocaleOnUntranslatedMappingIsAllowedAndAddsNoJoins(): void
    {
        $repository = new Repository($this->connection, $this->mapping(translated: false));
        $item = $repository->withLocale('uk', 'en')->find(1);

        self::assertSame('SKU-1', $item->sku);
        self::assertObjectNotHasProperty('name', $item);
        self::assertSame(0, $this->joinCount($this->logger->queries[0]));
        self::assertNotContains('uk', $this->logger->parameters[0]);
    }

    public function testLocaleValuesAreBoundParameters(): void
    {
        $locale = "uk' OR 1=1 --";
        $this->connection->insert('item_translations', [
            'item_id' => 1,
            'locale' => $locale,
            'name' => 'Exact unusual locale',
            'description' => null,
        ]);
        $this->resetLog();

        $item = $this->repository->withLocale($locale, 'en')->find(1);
        self::assertSame('Exact unusual locale', $item->name);
        self::assertSame('English one', $item->description);
        self::assertStringNotContainsString($locale, $this->logger->queries[0]);
        self::assertContains($locale, $this->logger->parameters[0]);
        self::assertContains('en', $this->logger->parameters[0]);
    }

    public function testEmptyLocalesAndInvalidLogicalFieldsFailBeforeSql(): void
    {
        foreach ([['', null], ['   ', null], ["\t\n", null], ['uk', ''], ['uk', ' ']] as [$locale, $fallback]) {
            try {
                $this->repository->withLocale($locale, $fallback);
                self::fail('Empty locales must be rejected when configuring the query.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->logger->queries);
            }
        }

        $query = $this->repository->withLocale('uk', 'en');
        $requests = [
            ['rows', [['missing']]],
            ['rows', [['name'], ['translations.name' => 'Bravo']]],
            ['rows', [['name'], [], ['COALESCE(name, description)' => 'ASC']]],
            ['findBy', [['name >' => null]]],
            ['findBy', [Filter::all(['name IN' => []], ['missing' => 1])]],
        ];
        foreach ($requests as [$method, $arguments]) {
            try {
                $query->{$method}(...$arguments);
                self::fail('Invalid logical fields must not become SQL.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->logger->queries);
            }
        }
    }

    public function testBaseWritesRejectTranslatedFieldsAndPredicatesBeforeSql(): void
    {
        $requests = [
            ['insert', [['sku' => 'new', 'name' => 'Translation']]],
            ['insertMany', [[['sku' => 'new', 'name' => 'Translation']]]],
            ['update', [1, ['name' => 'Translation']]],
            ['updateBy', [['name' => 'Bravo'], ['sku' => 'changed']]],
            ['deleteBy', [Filter::any(['id' => 1], ['description' => 'English one'])]],
        ];
        foreach ($requests as [$method, $arguments]) {
            try {
                $this->repository->{$method}(...$arguments);
                self::fail('Base writes must reject translated fields.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->logger->queries);
            }
        }
    }

    public function testKeysetRetainsLocaleFallbackTranslatedCriteriaAndVisibility(): void
    {
        $query = $this->repository->read()->withVisibility(Visibility::All)->withLocale('uk', 'en');
        $items = iterator_to_array($query->iterateById(
            ['name IN' => ['Bravo', 'Charlie', 'Hidden UK', 'Solo']],
            batchSize: 2,
        ), false);

        self::assertSame([1, 3, 9, 10], array_column($items, 'id'));
        self::assertSame(['Bravo', 'Charlie', 'Hidden UK', 'Solo'], array_column($items, 'name'));
        self::assertCount(3, $this->logger->queries);
        foreach ($this->logger->queries as $sql) {
            self::assertSame(2, $this->joinCount($sql));
            self::assertStringNotContainsString('OFFSET', $sql);
            self::assertStringNotContainsString('COUNT(', $sql);
        }
    }

    public function testProjectionParameterBudgetIncludesEveryLocaleJoin(): void
    {
        foreach ([['en', null, 899], ['uk', 'en', 898]] as [$locale, $fallback, $count]) {
            $this->resetLog();
            $query = $this->repository->withLocale($locale, $fallback);
            self::assertCount(9, $query->rows(['id', 'name'], ['id IN' => range(1, $count)]));
            self::assertCount(900, $this->logger->parameters[0]);

            $this->resetLog();
            try {
                $query->rows(['id', 'name'], ['id IN' => range(1, $count + 1)]);
                self::fail('Locale parameters must count toward the query limit.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->logger->queries);
            }
        }
    }

    public function testKeysetParameterBudgetReservesLocalesAndContinuationEagerly(): void
    {
        foreach ([['en', null, 898], ['uk', 'en', 897]] as [$locale, $fallback, $count]) {
            $this->resetLog();
            $query = $this->repository->withLocale($locale, $fallback);
            $iterable = $query->iterateById(['id IN' => range(1, $count)], batchSize: 2);
            self::assertSame([], $this->logger->queries);
            self::assertCount(9, iterator_to_array($iterable, false));
            self::assertCount(900, $this->logger->parameters[1]);

            $this->resetLog();
            try {
                $query->iterateById(['id IN' => range(1, $count + 1)], batchSize: 2);
                self::fail('Cursor and locale parameter capacity must be checked before iteration.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->logger->queries);
            }
        }
    }

    public function testFilterAndOrderOnlyJoinsAlsoConsumeParameterCapacity(): void
    {
        $query = $this->repository->withLocale('uk', 'en');
        $criteria = ['id IN' => range(1, 897), 'name' => 'Bravo'];
        self::assertSame(1, $query->count($criteria));
        self::assertTrue($query->exists($criteria));
        self::assertCount(900, $this->logger->parameters[0]);
        self::assertCount(900, $this->logger->parameters[1]);
        self::assertCount(9, $query->rows(['id'], ['id IN' => range(1, 898)], ['name' => 'ASC']));
        self::assertCount(900, $this->logger->parameters[2]);

        $this->resetLog();
        foreach (['count', 'exists', 'rows'] as $method) {
            try {
                if ($method === 'rows') {
                    $query->rows(['id'], ['id IN' => range(1, 899)], ['name' => 'ASC']);
                } else {
                    $query->{$method}(['id IN' => range(1, 898), 'name' => 'Bravo']);
                }
                self::fail('Joins needed only for WHERE or ORDER BY must count toward the parameter limit.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->logger->queries);
            }
        }
    }

    public function testFoldedTranslationFiltersDoNotAddUnusedJoinsOrParameters(): void
    {
        $query = $this->repository->withLocale('uk', 'en');
        $criteria = Filter::all(
            ['id IN' => range(1, 900)],
            Filter::any(['name' => 'Bravo'], ['id NOT IN' => []]),
        );

        self::assertCount(9, $query->rows(['id'], $criteria));
        self::assertSame(0, $this->joinCount($this->logger->queries[0]));
        self::assertCount(900, $this->logger->parameters[0]);
        self::assertNotContains('uk', $this->logger->parameters[0]);
    }

    public function testCustomStringKeyAndReservedIdentifiersResolveWithoutAmbiguousColumns(): void
    {
        $this->connection->executeStatement('CREATE TABLE "order" ("key" TEXT PRIMARY KEY, "language" TEXT)');
        $this->connection->executeStatement(
            'CREATE TABLE "group" ("key" TEXT, "language" TEXT, "select" TEXT, UNIQUE("key", "language"))',
        );
        $this->connection->executeStatement('INSERT INTO "order" VALUES (?, ?)', ['item-1', 'base-language']);
        $this->connection->executeStatement('INSERT INTO "group" VALUES (?, ?, ?)', ['item-1', 'en', 'Chair']);
        $repository = new Repository($this->connection, new Mapping(
            table: 'order',
            columns: ['key' => Types::STRING, 'language' => Types::STRING],
            hydrate: static fn(array $row): object => (object) $row,
            primaryKey: 'key',
            generatedId: false,
            translations: new Translations(
                table: 'group',
                foreignKey: 'key',
                columns: ['select' => Types::STRING],
                defaultLocale: 'en',
                localeColumn: 'language',
            ),
        ));

        $query = $repository->withLocale('uk', 'en');
        self::assertSame('Chair', $query->find('item-1')->select);
        self::assertSame(
            [['key' => 'item-1', 'language' => 'base-language', 'select' => 'Chair']],
            $query->rows(['key', 'language', 'select'], ['select' => 'Chair'], ['select' => 'ASC']),
        );
    }

    private function mapping(?Closure $hydrate = null, bool $translated = true, ?string $defaultLocale = 'en'): Mapping
    {
        return new Mapping(
            table: 'items',
            columns: [
                'id' => Types::INTEGER,
                'sku' => Types::STRING,
                'enabled' => Types::BOOLEAN,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'deleted_at' => Types::DATETIME_IMMUTABLE,
            ],
            hydrate: $hydrate ?? static fn(array $row): object => (object) $row,
            softDeleteColumn: 'deleted_at',
            translations: $translated ? new Translations(
                table: 'item_translations',
                foreignKey: 'item_id',
                columns: ['name' => Types::STRING, 'description' => Types::TEXT],
                defaultLocale: $defaultLocale,
                localeColumn: 'locale',
            ) : null,
        );
    }

    public function testQueryBuilderJoinsOnlyTheTranslationsItsCriteriaNeed(): void
    {
        $query = $this->repository->withLocale('uk', fallback: 'en');

        $builder = $query->queryBuilder('i', ['name LIKE' => '%o%'])->select('i.id')->orderBy('i.id');
        self::assertSame([1, 10], $builder->fetchFirstColumn());
        self::assertSame(2, $this->joinCount($this->logger->queries[0]));
        self::assertStringContainsString('"i_translation"."name"', $this->logger->queries[0]);
        self::assertStringContainsString('"i_fallback"."name"', $this->logger->queries[0]);
        self::assertSame(['uk', 'en', '%o%'], array_values($this->logger->parameters[0]));

        $this->resetLog();
        self::assertSame([2], $query->queryBuilder('i', ['enabled' => false])->select('i.id')->fetchFirstColumn());
        self::assertSame(0, $this->joinCount($this->logger->queries[0]));
    }

    public function testWithoutADefaultLocaleReadsJoinNoTranslationsAndReturnNullText(): void
    {
        $items = new Repository($this->connection, $this->mapping(defaultLocale: null));

        $item = $items->read()->find(1);
        self::assertSame([null, null], [$item->name, $item->description]);
        self::assertSame([['id' => 2, 'name' => null]], $items->read()->rows(['id', 'name'], ['id' => 2]));
        self::assertSame(2, $items->read()->count(['id IN' => [1, 2]]));
        self::assertCount(3, $this->logger->queries);
        foreach ($this->logger->queries as $sql) {
            self::assertSame(0, $this->joinCount($sql));
        }
        self::assertSame('Bravo', $items->withLocale('en')->find(1)->name);
    }

    #[DataProvider('readsNeedingALocale')]
    public function testTranslatedConditionsSortsAndAggregatesNeedALocaleWithoutADefault(Closure $read): void
    {
        $items = new Repository($this->connection, $this->mapping(defaultLocale: null));

        try {
            $read($items->read());
            self::fail('A translated field without a locale must be rejected.');
        } catch (LogicException $exception) {
            self::assertSame(
                'Translated field "name" needs a locale: read through withLocale() or give the mapping a default locale.',
                $exception->getMessage(),
            );
            self::assertSame([], $this->logger->queries);
        }
    }

    public static function readsNeedingALocale(): iterable
    {
        yield 'condition' => [static fn(ReadQuery $query): mixed => $query->findBy(['name' => 'Bravo'])];
        yield 'order' => [static fn(ReadQuery $query): mixed => $query->findBy([], ['name' => 'ASC'])];
        yield 'aggregate' => [static fn(ReadQuery $query): mixed => $query->max('name')];
        yield 'count' => [static fn(ReadQuery $query): mixed => $query->count(['name LIKE' => 'B%'])];
        yield 'query builder' => [static fn(ReadQuery $query): mixed => $query->queryBuilder('i', ['name' => 'Bravo'])];
    }

    private function resetLog(): void
    {
        $this->logger->queries = [];
        $this->logger->parameters = [];
    }

    private function joinCount(string $sql): int
    {
        return substr_count(strtoupper($sql), ' JOIN ');
    }
}
