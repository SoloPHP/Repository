<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Solo\Repository\Mapping;
use Solo\Repository\Repository;
use Solo\Repository\Translations;
use Solo\Repository\TranslationStore;
use Stringable;

final class TranslationStoreTest extends TestCase
{
    private Connection $connection;
    private TranslationStore $store;
    private AbstractLogger $log;

    protected function setUp(): void
    {
        $this->log = new class extends AbstractLogger {
            public array $sql = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $this->sql[] = $context['sql'];
                }
            }
        };
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->log)]);
        $this->connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            $configuration,
        );
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->connection->executeStatement('CREATE TABLE items (
            id INTEGER PRIMARY KEY, sku TEXT NOT NULL, deleted_at DATETIME
        )');
        $this->connection->executeStatement('CREATE TABLE item_translations (
            item_id INTEGER NOT NULL REFERENCES items (id),
            locale TEXT NOT NULL,
            name TEXT DEFAULT \'database name\',
            description TEXT DEFAULT \'database description\',
            unexposed TEXT DEFAULT \'not mapped\',
            UNIQUE (item_id, locale)
        )');
        $this->connection->insert('items', ['id' => 1, 'sku' => 'active']);
        $this->connection->insert('items', ['id' => 2, 'sku' => 'deleted', 'deleted_at' => '2026-01-01 12:00:00']);
        $this->store = $this->translationStore($this->connection, $this->mapping());
        $this->log->sql = [];
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testSaveUsesOneAtomicStatementWithoutReadingOrHydrating(): void
    {
        $mapping = new Mapping(
            table: 'items',
            columns: ['id' => Types::INTEGER],
            hydrate: static fn (): never => throw new RuntimeException('Hydration must not run.'),
            translations: $this->translations(),
        );
        $store = $this->translationStore($this->connection, $mapping);
        $store->save('1', 'uk', ['name' => 'Стіл']);

        self::assertCount(1, $this->log->sql);
        self::assertStringStartsWith('INSERT INTO', $this->log->sql[0]);
        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame(['name' => 'Стіл', 'description' => null], $store->get(1, 'uk'));
    }

    public function testPatchPreservesOmittedExistingFieldsAndNullsNewFieldsDespiteDefaults(): void
    {
        $this->store->save(1, 'uk', ['name' => 'Initial']);
        self::assertSame(['name' => 'Initial', 'description' => null], $this->store->get(1, 'uk'));

        $this->store->save(1, 'uk', ['description' => 'Description']);
        self::assertSame(['name' => 'Initial', 'description' => 'Description'], $this->store->get(1, 'uk'));

        $this->store->save(1, 'uk', ['name' => 'Changed']);
        self::assertSame(['name' => 'Changed', 'description' => 'Description'], $this->store->get(1, 'uk'));
    }

    public function testNullEmptyAndZeroStringsRemainDistinct(): void
    {
        $this->store->save(1, 'en', ['name' => 'Fallback', 'description' => 'Fallback description']);
        $this->store->save(1, 'uk', ['name' => '', 'description' => '0']);
        self::assertSame(['name' => '', 'description' => '0'], $this->store->get(1, 'uk'));

        $this->store->save(1, 'uk', ['name' => null, 'description' => null]);
        self::assertSame(['name' => null, 'description' => null], $this->store->get(1, 'uk'));
        self::assertNull($this->store->get(1, 'de'));
    }

    public function testGetReturnsOnlyConfiguredFieldsForTheRequestedOwnerAndLocale(): void
    {
        $this->store->save(1, 'en', ['name' => 'English']);
        $this->store->save(1, 'uk', ['name' => 'Українська']);
        $this->store->save(2, 'en', ['name' => 'Other owner']);

        self::assertSame(['name' => 'English', 'description' => null], $this->store->get(1, 'en'));
        self::assertSame(['name' => 'Українська', 'description' => null], $this->store->get(1, 'uk'));
        self::assertSame(['name' => 'Other owner', 'description' => null], $this->store->get(2, 'en'));
        self::assertNull($this->store->get(999, 'en'));
    }

    public function testSoftDeletedOwnersRemainEditable(): void
    {
        $this->store->save(2, 'uk', ['name' => 'Deleted owner translation']);
        self::assertSame(
            ['name' => 'Deleted owner translation', 'description' => null],
            $this->store->get(2, 'uk'),
        );
        self::assertSame(1, $this->store->delete(2, 'uk'));
        self::assertNull($this->store->get(2, 'uk'));
    }

    public function testDeleteIsScopedToOneOwnerAndLocale(): void
    {
        $this->store->save(1, 'en', ['name' => 'English']);
        $this->store->save(1, 'uk', ['name' => 'Українська']);
        $this->store->save(2, 'en', ['name' => 'Other']);
        $this->log->sql = [];

        self::assertSame(1, $this->store->delete('1', 'en'));
        self::assertCount(1, $this->log->sql);
        self::assertStringStartsWith('DELETE FROM', $this->log->sql[0]);
        self::assertSame(0, $this->store->delete(1, 'en'));
        self::assertNull($this->store->get(1, 'en'));
        self::assertNotNull($this->store->get(1, 'uk'));
        self::assertNotNull($this->store->get(2, 'en'));
    }

    public function testEmptyPatchIsANoOpAndDoesNotCreateARow(): void
    {
        $this->store->save(1, 'en', []);
        $this->store->save(999, 'en', []);
        self::assertSame([], $this->log->sql);
        self::assertNull($this->store->get(1, 'en'));
    }

    public function testCallerTransactionRollsBackTranslationWrites(): void
    {
        $this->store->save(1, 'en', ['name' => 'Original']);

        try {
            $this->connection->transactional(function (): void {
                $this->store->save(1, 'en', ['name' => 'Changed']);
                $this->store->save(1, 'uk', ['name' => 'New']);
                throw new RuntimeException('abort');
            });
            self::fail('Expected the caller to abort the transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('abort', $exception->getMessage());
        }

        self::assertSame(['name' => 'Original', 'description' => null], $this->store->get(1, 'en'));
        self::assertNull($this->store->get(1, 'uk'));
        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testForeignKeyErrorsAreNotHiddenAndRollBackTheCallerTransaction(): void
    {
        try {
            $this->connection->transactional(function (): void {
                $this->store->save(1, 'en', ['name' => 'Must roll back']);
                $this->store->save(999, 'en', ['name' => 'Missing owner']);
            });
            self::fail('Expected a foreign key violation.');
        } catch (ForeignKeyConstraintViolationException) {
            self::assertNull($this->store->get(1, 'en'));
            self::assertNull($this->store->get(999, 'en'));
            self::assertFalse($this->connection->isTransactionActive());
        }
    }

    #[DataProvider('invalidPatches')]
    public function testInvalidPatchIsRejectedBeforeSql(array $patch): void
    {
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->store->save(1, 'en', $patch);
        } finally {
            self::assertSame([], $this->log->sql);
        }
    }

    public static function invalidPatches(): iterable
    {
        yield 'owner key' => [['item_id' => '2']];
        yield 'locale key' => [['locale' => 'uk']];
        yield 'base column' => [['sku' => 'Changed']];
        yield 'primary key' => [['id' => '2']];
        yield 'soft delete column' => [['deleted_at' => null]];
        yield 'unexposed physical column' => [['unexposed' => 'Changed']];
        yield 'sql fragment' => [['name) VALUES (?) --' => 'Changed']];
        yield 'integer key' => [['Name']];
        yield 'integer value' => [['name' => 42]];
        yield 'boolean value' => [['name' => false]];
        yield 'array value' => [['name' => []]];
        yield 'object value' => [['name' => new \stdClass()]];
        yield 'later invalid value' => [['name' => 'Valid', 'description' => 42]];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidIdsAndLocalesAreRejectedBeforeSql(
        string $operation,
        int|string $id,
        string $locale,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        try {
            if ($operation === 'save') {
                $this->store->save($id, $locale, []);
            } else {
                $this->store->{$operation}($id, $locale);
            }
        } finally {
            self::assertSame([], $this->log->sql);
        }
    }

    public static function invalidInputs(): iterable
    {
        foreach (['get', 'save', 'delete'] as $operation) {
            yield $operation . ' invalid ID' => [$operation, 'garbage', 'en'];
            yield $operation . ' noncanonical ID' => [$operation, '01', 'en'];
            yield $operation . ' empty locale' => [$operation, 1, ''];
            yield $operation . ' whitespace locale' => [$operation, 1, " \t\n"];
        }
    }

    public function testStringIdsReservedIdentifiersAndMaliciousValuesAreBoundSafely(): void
    {
        $this->connection->executeStatement('CREATE TABLE "order" ("key" TEXT PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE "group" (
            "order" TEXT NOT NULL REFERENCES "order" ("key"), "where" TEXT NOT NULL,
            "select" TEXT, UNIQUE ("order", "where")
        )');
        $id = "owner' OR 1 = 1 --";
        $locale = "uk' OR 1 = 1 --";
        $value = "value'); DROP TABLE items; --";
        $this->connection->insert('"order"', ['"key"' => $id]);
        $store = $this->translationStore($this->connection, new Mapping(
            table: 'order',
            columns: ['key' => Types::STRING],
            hydrate: static fn (array $row): object => (object) $row,
            primaryKey: 'key',
            generatedId: false,
            translations: new Translations('group', 'order', ['select' => Types::TEXT], 'en', 'where'),
        ));
        $this->log->sql = [];
        $store->save($id, $locale, ['select' => $value]);
        self::assertCount(1, $this->log->sql);
        self::assertStringNotContainsString($id, $this->log->sql[0]);
        self::assertStringNotContainsString($locale, $this->log->sql[0]);
        self::assertStringNotContainsString($value, $this->log->sql[0]);
        self::assertSame(['select' => $value], $store->get($id, $locale));
        self::assertSame(1, $store->delete($id, $locale));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM items'));
    }

    public function testMatchingStringKeyCollationsPreserveUniqueTranslationsAndReadCardinality(): void
    {
        $this->connection->executeStatement('CREATE TABLE owners (id TEXT COLLATE NOCASE PRIMARY KEY)');
        $this->connection->executeStatement(
            'CREATE TABLE owner_translations (owner_id TEXT COLLATE NOCASE REFERENCES owners(id), '
                . 'locale TEXT, name TEXT, UNIQUE(owner_id, locale))',
        );
        $this->connection->insert('owners', ['id' => 'Owner']);
        $repository = new Repository($this->connection, new Mapping(
            table: 'owners',
            columns: ['id' => Types::STRING],
            hydrate: static fn(array $row): object => (object) $row,
            generatedId: false,
            translations: new Translations('owner_translations', 'owner_id', ['name' => Types::STRING], 'en'),
        ));
        $store = $repository->translations();
        $store->save('OWNER', 'en', ['name' => 'First']);
        $store->save('owner', 'en', ['name' => 'Updated']);

        self::assertSame(['name' => 'Updated'], $store->get('oWnEr', 'en'));
        self::assertSame(1, $repository->read()->count());
        self::assertSame(1, $repository->read()->count(['name !=' => null]));
        self::assertSame(
            [['id' => 'Owner', 'name' => 'Updated']],
            $repository->read()->rows(['id', 'name'], limit: 2),
        );
        self::assertSame(1, $store->delete('oWnEr', 'en'));
        self::assertNull($store->get('Owner', 'en'));
    }

    public function testConstructionRequiresTranslationMetadata(): void
    {
        $this->expectException(LogicException::class);
        $this->translationStore($this->connection, new Mapping(
            table: 'items',
            columns: ['id' => Types::INTEGER],
            hydrate: static fn (array $row): object => (object) $row,
        ));
    }

    public function testSqliteUpsertCanUseATableNamedExcluded(): void
    {
        $this->connection->executeStatement('CREATE TABLE excluded (
            item_id INTEGER NOT NULL REFERENCES items (id), locale TEXT NOT NULL,
            name TEXT, description TEXT, UNIQUE (item_id, locale)
        )');
        $mapping = new Mapping(
            table: 'items',
            columns: ['id' => Types::INTEGER],
            hydrate: static fn (array $row): object => (object) $row,
            translations: new Translations(
                'excluded',
                'item_id',
                ['name' => Types::STRING, 'description' => Types::TEXT],
                'en',
            ),
        );
        $store = $this->translationStore($this->connection, $mapping);
        $store->save(1, 'en', ['name' => 'Original', 'description' => 'Unchanged']);
        $store->save(1, 'en', ['name' => 'Changed']);

        self::assertSame(['name' => 'Changed', 'description' => 'Unchanged'], $store->get(1, 'en'));
    }

    #[DataProvider('baseWriteOperations')]
    public function testTranslatedFieldsStayOutOfBaseWritesAndWriteCriteria(string $operation): void
    {
        $repository = new Repository($this->connection, $this->mapping());
        $this->expectException(InvalidArgumentException::class);
        try {
            match ($operation) {
                'insert' => $repository->insert(['sku' => 'new', 'name' => 'Translation']),
                'update' => $repository->update(1, ['name' => 'Translation']),
                'updateBy' => $repository->updateBy(['name' => 'Translation'], ['sku' => 'Changed']),
                'deleteBy' => $repository->deleteBy(['name' => 'Translation']),
            };
        } finally {
            self::assertSame([], $this->log->sql);
        }
    }

    public static function baseWriteOperations(): iterable
    {
        foreach (['insert', 'update', 'updateBy', 'deleteBy'] as $operation) {
            yield $operation => [$operation];
        }
    }

    public function testPostgresUpsertHasAnExplicitConflictTargetAndOnlyUpdatesPatchedFields(): void
    {
        $connection = $this->mockConnection(new PostgreSQLPlatform());
        $connection->expects(self::never())->method('executeQuery');
        $connection->expects(self::once())->method('executeStatement')->with(
            'INSERT INTO "excluded" AS "br_target" ("item_id", "locale", "name", "description") VALUES (?, ?, ?, ?)'
                . ' ON CONFLICT ("item_id", "locale") DO UPDATE SET "name" = excluded."name"',
            [7, 'uk', 'Desk', null],
            [Types::INTEGER, Types::STRING, Types::STRING, Types::TEXT],
        )->willReturn(1);

        $mapping = new Mapping(
            table: 'items',
            columns: ['id' => Types::INTEGER],
            hydrate: static fn (array $row): object => (object) $row,
            translations: new Translations(
                'excluded',
                'item_id',
                ['name' => Types::STRING, 'description' => Types::TEXT],
                'en',
            ),
        );
        $this->translationStore($connection, $mapping)->save('7', 'uk', ['name' => 'Desk']);
    }

    public function testMysqlUpsertUsesSeparateTypedUpdateParametersWithoutDeprecatedValuesFunction(): void
    {
        $connection = $this->mockConnection(new MySQLPlatform());
        $connection->expects(self::never())->method('executeQuery');
        $connection->expects(self::once())->method('executeStatement')->with(
            'INSERT INTO `item_translations` (`item_id`, `locale`, `name`, `description`) VALUES (?, ?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE `description` = ?, `name` = ?',
            [7, 'uk', '', null, null, ''],
            [Types::INTEGER, Types::STRING, Types::STRING, Types::TEXT, Types::TEXT, Types::STRING],
        )->willReturn(1);

        $this->translationStore($connection, $this->mapping())->save('7', 'uk', ['description' => null, 'name' => '']);
    }

    #[DataProvider('unsupportedPlatforms')]
    public function testUnsupportedUpsertPlatformsFailBeforeSql(AbstractPlatform $platform): void
    {
        $connection = $this->mockConnection($platform);
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('executeQuery');
        $store = $this->translationStore($connection, $this->mapping());
        $this->expectException(LogicException::class);
        $store->save(1, 'en', ['name' => 'Unsupported']);
    }

    public static function unsupportedPlatforms(): iterable
    {
        yield 'SQL Server' => [new SQLServerPlatform()];
    }

    public function testMariaDbUsesTheMysqlUpsert(): void
    {
        $connection = $this->mockConnection(new MariaDBPlatform());
        $connection->expects(self::once())->method('executeStatement')->with(
            'INSERT INTO `item_translations` (`item_id`, `locale`, `name`, `description`) VALUES (?, ?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE `name` = ?',
            [7, 'uk', 'Desk', null, 'Desk'],
            [Types::INTEGER, Types::STRING, Types::STRING, Types::TEXT, Types::STRING],
        )->willReturn(1);

        $this->translationStore($connection, $this->mapping())->save(7, 'uk', ['name' => 'Desk']);
    }

    #[DataProvider('parameterLimits')]
    public function testUpsertParameterLimitIncludesOmittedFieldsAndMysqlUpdateBindings(
        AbstractPlatform $platform,
        int $fieldCount,
        int $patchCount,
        bool $allowed,
    ): void {
        $columns = [];
        for ($index = 0; $index < $fieldCount; $index++) {
            $columns['field_' . $index] = Types::STRING;
        }
        $patch = array_fill_keys(array_slice(array_keys($columns), 0, $patchCount), 'value');
        $connection = $this->mockConnection($platform);
        if ($allowed) {
            $connection->expects(self::once())->method('executeStatement')->with(
                self::anything(),
                self::callback(static fn (array $parameters): bool => count($parameters) === 900),
                self::callback(static fn (array $types): bool => count($types) === 900),
            )->willReturn(1);
        } else {
            $connection->expects(self::never())->method('executeStatement');
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('parameter limit');
        }

        $mapping = new Mapping(
            table: 'items',
            columns: ['id' => Types::INTEGER],
            hydrate: static fn (array $row): object => (object) $row,
            translations: new Translations('item_translations', 'item_id', $columns, 'en'),
        );
        $this->translationStore($connection, $mapping, 900)->save(1, 'en', $patch);
    }

    public static function parameterLimits(): iterable
    {
        $maximumFields = 898;
        yield 'SQLite exact limit' => [new SQLitePlatform(), $maximumFields, 1, true];
        yield 'SQLite over limit' => [new SQLitePlatform(), $maximumFields + 1, 1, false];
        yield 'Postgres exact limit' => [new PostgreSQLPlatform(), $maximumFields, 1, true];
        yield 'Postgres over limit' => [new PostgreSQLPlatform(), $maximumFields + 1, 1, false];
        yield 'MySQL exact limit with duplicated patch' => [new MySQLPlatform(), 449, 449, true];
        yield 'MySQL over limit with duplicated patch' => [new MySQLPlatform(), 450, 449, false];
    }

    public function testSeedInsertsOnlyMissingLocalesAndKeepsExistingTranslations(): void
    {
        $this->store->save(1, 'en', ['name' => 'Existing', 'description' => 'Kept']);
        $this->log->sql = [];

        self::assertSame(2, $this->store->seed(1, ['en', 'uk', 'de', 'uk'], ['name' => 'Seeded']));
        self::assertCount(1, $this->log->sql);
        self::assertSame(['name' => 'Existing', 'description' => 'Kept'], $this->store->get(1, 'en'));
        self::assertSame(['name' => 'Seeded', 'description' => null], $this->store->get(1, 'uk'));
        self::assertSame(['name' => 'Seeded', 'description' => null], $this->store->get(1, 'de'));
        self::assertSame(0, $this->store->seed(1, ['uk'], ['name' => 'Ignored']));
        self::assertSame(['name' => 'Seeded', 'description' => null], $this->store->get(1, 'uk'));
    }

    public function testEmptySeedIsANoOpAfterValidation(): void
    {
        self::assertSame(0, $this->store->seed(1, [], ['name' => 'Unused']));
        self::assertSame(0, $this->store->seed(1, ['en'], []));
        self::assertSame([], $this->log->sql);
    }

    #[DataProvider('invalidSeeds')]
    public function testInvalidSeedIsRejectedBeforeSql(int|string $id, array $locales, array $values): void
    {
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->store->seed($id, $locales, $values);
        } finally {
            self::assertSame([], $this->log->sql);
        }
    }

    public static function invalidSeeds(): iterable
    {
        yield 'invalid ID' => ['garbage', ['en'], ['name' => 'Value']];
        yield 'blank locale' => [1, ['en', ' '], ['name' => 'Value']];
        yield 'locale type' => [1, [1], ['name' => 'Value']];
        yield 'unknown field' => [1, ['en'], ['sku' => 'Value']];
        yield 'value type' => [1, ['en'], ['name' => 1]];
    }

    public function testSeedSplitsLocalesAtTheParameterLimitAndRollsBackEveryChunk(): void
    {
        $this->connection->executeStatement('CREATE TABLE checked_translations (
            item_id INTEGER NOT NULL REFERENCES items (id), locale TEXT NOT NULL CHECK (locale <> \'xx\'),
            name TEXT, description TEXT, UNIQUE (item_id, locale)
        )');
        $mapping = new Mapping(
            table: 'items',
            columns: ['id' => Types::INTEGER],
            hydrate: static fn (array $row): object => (object) $row,
            translations: new Translations(
                'checked_translations',
                'item_id',
                ['name' => Types::STRING, 'description' => Types::TEXT],
                'en',
            ),
        );
        $store = $this->translationStore($this->connection, $mapping, 8);
        $this->log->sql = [];

        self::assertSame(3, $store->seed(1, ['en', 'uk', 'de'], ['name' => 'Seeded']));
        self::assertCount(2, $this->log->sql);
        try {
            $store->seed(2, ['en', 'uk', 'xx'], ['name' => 'Rolled back']);
            self::fail('The CHECK constraint must reject the second chunk.');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
            self::assertNull($store->get(2, 'en'));
            self::assertFalse($this->connection->isTransactionActive());
        }
    }

    public function testMysqlSeedTurnsDuplicatesIntoANoOpUpdate(): void
    {
        $connection = $this->mockConnection(new MySQLPlatform());
        $connection->expects(self::once())->method('executeStatement')->with(
            'INSERT INTO `item_translations` (`item_id`, `locale`, `name`, `description`)'
                . ' VALUES (?, ?, ?, ?), (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `item_id` = `item_id`',
            [7, 'en', 'Desk', null, 7, 'uk', 'Desk', null],
            [
                Types::INTEGER, Types::STRING, Types::STRING, Types::TEXT,
                Types::INTEGER, Types::STRING, Types::STRING, Types::TEXT,
            ],
        )->willReturn(1);

        self::assertSame(1, $this->translationStore($connection, $this->mapping())->seed('7', ['en', 'uk'], [
            'name' => 'Desk',
        ]));
    }

    public function testPostgresSeedSkipsConflictsOnTheTranslationKeyOnly(): void
    {
        $connection = $this->mockConnection(new PostgreSQLPlatform());
        $connection->expects(self::once())->method('executeStatement')->with(
            'INSERT INTO "item_translations" AS "br_target" ("item_id", "locale", "name", "description")'
                . ' VALUES (?, ?, ?, ?) ON CONFLICT ("item_id", "locale") DO NOTHING',
            [7, 'uk', 'Desk', 'Oak'],
            [Types::INTEGER, Types::STRING, Types::STRING, Types::TEXT],
        )->willReturn(1);

        self::assertSame(1, $this->translationStore($connection, $this->mapping())->seed(7, ['uk'], [
            'name' => 'Desk',
            'description' => 'Oak',
        ]));
    }

    #[DataProvider('unsupportedPlatforms')]
    public function testUnsupportedSeedPlatformsFailBeforeSql(AbstractPlatform $platform): void
    {
        $connection = $this->mockConnection($platform);
        $connection->expects(self::never())->method('executeStatement');
        $this->expectException(LogicException::class);
        $this->translationStore($connection, $this->mapping())->seed(1, ['en'], ['name' => 'Unsupported']);
    }

    public function testGetConvertsTextStreamsAndFreesTheResult(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'Long text');
        rewind($stream);
        $result = $this->createMock(Result::class);
        $result->expects(self::once())->method('fetchAssociative')->willReturn([
            'name' => null,
            'description' => $stream,
        ]);
        $result->expects(self::once())->method('free');
        $connection = $this->mockConnection(new PostgreSQLPlatform());
        $connection->expects(self::once())->method('executeQuery')->with(
            'SELECT "name", "description" FROM "item_translations" WHERE "item_id" = ? AND "locale" = ?',
            [7, 'uk'],
            [Types::INTEGER, Types::STRING],
        )->willReturn($result);

        try {
            self::assertSame(
                ['name' => null, 'description' => 'Long text'],
                $this->translationStore($connection, $this->mapping())->get('7', 'uk'),
            );
        } finally {
            fclose($stream);
        }
    }

    public function testReadErrorsPropagateAndTheResultIsFreed(): void
    {
        $failure = new RuntimeException('read failed');
        $result = $this->createMock(Result::class);
        $result->expects(self::once())->method('fetchAssociative')->willThrowException($failure);
        $result->expects(self::once())->method('free');
        $connection = $this->mockConnection(new SQLitePlatform());
        $connection->expects(self::once())->method('executeQuery')->willReturn($result);

        $this->expectExceptionObject($failure);
        $this->translationStore($connection, $this->mapping())->get(1, 'en');
    }

    private function mapping(): Mapping
    {
        return new Mapping(
            table: 'items',
            columns: ['id' => Types::INTEGER, 'sku' => Types::STRING, 'deleted_at' => Types::DATETIME_IMMUTABLE],
            hydrate: static fn (array $row): object => (object) $row,
            softDeleteColumn: 'deleted_at',
            translations: $this->translations(),
        );
    }

    private function translations(): Translations
    {
        return new Translations(
            table: 'item_translations',
            foreignKey: 'item_id',
            columns: ['name' => Types::STRING, 'description' => Types::TEXT],
            defaultLocale: 'en',
        );
    }

    private function mockConnection(AbstractPlatform $platform): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        return $connection;
    }

    private function translationStore(Connection $connection, Mapping $mapping, ?int $limit = null): TranslationStore
    {
        return (new Repository($connection, $mapping, parameterLimit: $limit))->translations();
    }
}
