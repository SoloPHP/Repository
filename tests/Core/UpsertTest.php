<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Repository\Mapping;
use Solo\Repository\Repository;
use Solo\Repository\Tests\Fixtures\QueryLog;
use Solo\Repository\Tests\Fixtures\RecordsStatements;
use Solo\Repository\Visibility;

final class UpsertTest extends TestCase
{
    use RecordsStatements;

    private const array KEY = ['owner_id' => 1, 'channel' => 'email'];

    private Connection $connection;
    private QueryLog $log;
    private Repository $settings;

    protected function setUp(): void
    {
        $this->log = new QueryLog();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->log)]);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->connection->executeStatement('CREATE TABLE settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id INTEGER NOT NULL,
            channel TEXT NOT NULL,
            enabled BOOLEAN NOT NULL DEFAULT 0,
            threshold INTEGER,
            created_at DATETIME,
            deleted_at DATETIME,
            UNIQUE (owner_id, channel)
        )');
        $this->settings = new Repository($this->connection, $this->mapping());
        $this->log->reset();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testInsertsTheRowOfANewKeyInOneStatement(): void
    {
        $created = new DateTimeImmutable('2026-01-02 03:04:05');

        $this->settings->upsert(self::KEY, ['enabled' => true], ['threshold' => 5, 'created_at' => $created]);

        self::assertSame([
            'INSERT INTO "settings" AS "br_target" ("owner_id", "channel", "enabled", "threshold", "created_at")'
            . ' VALUES (?, ?, ?, ?, ?) ON CONFLICT ("owner_id", "channel")'
            . ' DO UPDATE SET "enabled" = excluded."enabled"',
        ], $this->log->statements());
        $row = $this->settings->read()->findOneBy(self::KEY);
        self::assertSame(1, $row?->id);
        self::assertTrue($row->enabled);
        self::assertSame(5, $row->threshold);
        self::assertEquals($created, $row->created_at);
    }

    public function testUpdatesTheRowHoldingTheKeyAndKeepsTheRestOfIt(): void
    {
        $created = new DateTimeImmutable('2026-01-02 03:04:05');
        $this->settings->upsert(self::KEY, ['enabled' => true], ['threshold' => 5, 'created_at' => $created]);
        $this->settings->upsert(['owner_id' => 2, 'channel' => 'email'], ['enabled' => true]);

        $this->settings->upsert(
            self::KEY,
            ['enabled' => false, 'threshold' => 9],
            ['created_at' => new DateTimeImmutable('2027-01-01 00:00:00')],
        );
        $this->settings->upsert(self::KEY, ['enabled' => true]);

        $row = $this->settings->read()->find(1);
        self::assertTrue($row?->enabled);
        self::assertSame(9, $row->threshold);
        self::assertEquals($created, $row->created_at);
        self::assertSame(2, $this->settings->read()->count());
        self::assertSame(2, $this->settings->read()->findOneBy(['owner_id' => 2])?->id);
    }

    public function testEmptyDataOnlyInsertsAMissingRow(): void
    {
        $this->settings->upsert(self::KEY, [], ['threshold' => 1]);
        $this->settings->upsert(self::KEY, [], ['threshold' => 2]);

        $row = $this->settings->read()->findOneBy(self::KEY);
        self::assertSame(1, $row?->threshold);
        self::assertFalse($row->enabled);
        self::assertStringEndsWith(' ON CONFLICT ("owner_id", "channel") DO NOTHING', $this->log->statements()[1]);
    }

    public function testASoftDeletedRowHoldingTheKeyIsUpdatedAndStaysDeleted(): void
    {
        $this->settings->upsert(self::KEY, ['threshold' => 1]);
        $this->settings->delete(1);

        $this->settings->upsert(self::KEY, ['threshold' => 2]);

        self::assertNull($this->settings->read()->find(1));
        self::assertSame(2, $this->settings->read()->withVisibility(Visibility::Deleted)->find(1)?->threshold);
        self::assertSame(1, $this->settings->read()->withVisibility(Visibility::All)->count());
    }

    public function testAnExplicitIdComesFromTheKeyOrTheInsertOnlyValues(): void
    {
        $settings = new Repository($this->connection, $this->mapping(generatedId: false));
        try {
            $settings->upsert(self::KEY, ['enabled' => true]);
            self::fail('A new row of this mapping needs its ID.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('An explicit primary key is required by this mapping.', $exception->getMessage());
            self::assertSame([], $this->log->statements());
        }

        $settings->upsert(self::KEY, ['enabled' => true], ['id' => 70]);
        $settings->upsert(['id' => 70], ['threshold' => 3], self::KEY);

        self::assertSame(3, $settings->read()->find(70)?->threshold);
        self::assertSame(1, $settings->read()->count());
        self::assertStringEndsWith(
            ' ON CONFLICT ("id") DO UPDATE SET "threshold" = excluded."threshold"',
            $this->log->statements()[1],
        );
    }

    public function testMappingsWithoutAPrimaryKeyUpsertByTheirUniqueColumns(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE memberships (group_id INTEGER NOT NULL, user_id INTEGER NOT NULL, '
            . 'role TEXT NOT NULL, PRIMARY KEY (group_id, user_id))',
        );
        $memberships = new Repository($this->connection, new Mapping(
            table: 'memberships',
            columns: ['group_id' => Types::INTEGER, 'user_id' => Types::INTEGER, 'role' => Types::STRING],
            primaryKey: null,
        ));

        $memberships->upsert(['group_id' => 1, 'user_id' => 2], ['role' => 'member']);
        $memberships->upsert(['group_id' => 1, 'user_id' => 3], [], ['role' => 'member']);
        $memberships->upsert(['group_id' => 1, 'user_id' => 2], ['role' => 'owner']);
        $memberships->upsert(['group_id' => 1, 'user_id' => 3], [], ['role' => 'owner']);

        self::assertSame(
            [['user_id' => 2, 'role' => 'owner'], ['user_id' => 3, 'role' => 'member']],
            $memberships->read()->rows(['user_id', 'role'], orderBy: ['user_id' => 'ASC']),
        );
    }

    #[DataProvider('invalidUpserts')]
    public function testInvalidUpsertsFailBeforeSql(array $key, array $data, array $onInsert, string $message): void
    {
        try {
            $this->settings->upsert($key, $data, $onInsert);
            self::fail('Expected the upsert to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertSame([], $this->log->statements());
        }
    }

    public static function invalidUpserts(): iterable
    {
        $overlap = 'An upsert names each column once: in the key, the data or the insert-only values.';
        $reserved = 'Use dedicated operations instead of changing ID or visibility.';
        $deleted = new DateTimeImmutable('2026-01-01 00:00:00');

        yield 'empty key' => [[], ['enabled' => true], [], 'An upsert requires the columns of a unique key.'];
        yield 'NULL in the key' => [['owner_id' => 1, 'channel' => null], [], [], 'Upsert key values cannot be NULL.'];
        yield 'list key' => [[1], [], [], 'Data must be keyed by mapped column names.'];
        yield 'unknown key column' => [['owner' => 1], [], [], 'Unknown mapped column "owner".'];
        yield 'unknown data column' => [self::KEY, ['missing' => 1], [], 'Unknown mapped column "missing".'];
        yield 'unknown insert-only column' => [self::KEY, [], ['missing' => 1], 'Unknown mapped column "missing".'];
        yield 'key column in the data' => [self::KEY, ['channel' => 'sms'], [], $overlap];
        yield 'key column in the insert-only values' => [self::KEY, [], ['owner_id' => 2], $overlap];
        yield 'data in the insert-only values' => [self::KEY, ['threshold' => 1], ['threshold' => 2], $overlap];
        yield 'ID in the data' => [self::KEY, ['id' => 9], [], $reserved];
        yield 'soft-delete column in the data' => [self::KEY, ['deleted_at' => $deleted], [], $reserved];
        yield 'soft-delete column in the insert-only values' => [self::KEY, [], ['deleted_at' => $deleted], $reserved];
        yield 'soft-delete column in the key' => [['deleted_at' => $deleted], [], [], $reserved];
        yield 'value of another type' => [
            self::KEY,
            ['threshold' => '12x'],
            [],
            'Field "threshold" requires an integer or a canonical decimal string.',
        ];
        yield 'insert-only value of another type' => [
            self::KEY,
            [],
            ['enabled' => 'yes'],
            'Field "enabled" requires a boolean, 0 or 1.',
        ];
    }

    public function testMysqlBindsTheUpdatedValuesAndKeepsARowForEmptyData(): void
    {
        [$connection, $statements] = $this->recordingConnection(new MySQLPlatform());
        $settings = new Repository($connection, $this->mapping());
        $created = new DateTimeImmutable('2026-01-02 03:04:05');

        $settings->upsert(self::KEY, ['enabled' => true, 'threshold' => 5], ['created_at' => $created]);
        $settings->upsert(self::KEY, []);

        self::assertSame([
            [
                'INSERT INTO `settings` (`owner_id`, `channel`, `enabled`, `threshold`, `created_at`)'
                . ' VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `enabled` = ?, `threshold` = ?',
                [1, 'email', true, 5, $created, true, 5],
                [Types::INTEGER, Types::STRING, Types::BOOLEAN, Types::INTEGER, Types::DATETIME_IMMUTABLE,
                    Types::BOOLEAN, Types::INTEGER],
            ],
            [
                'INSERT INTO `settings` (`owner_id`, `channel`) VALUES (?, ?)'
                . ' ON DUPLICATE KEY UPDATE `owner_id` = `owner_id`',
                [1, 'email'],
                [Types::INTEGER, Types::STRING],
            ],
        ], $statements->getArrayCopy());
    }

    public function testPostgresUpdatesFromTheProposedRow(): void
    {
        [$connection, $statements] = $this->recordingConnection(new PostgreSQLPlatform());
        $settings = new Repository($connection, $this->mapping());

        $settings->upsert(self::KEY, ['enabled' => true, 'threshold' => 5]);
        $settings->upsert(self::KEY, []);

        self::assertSame([
            [
                'INSERT INTO "settings" AS "br_target" ("owner_id", "channel", "enabled", "threshold")'
                . ' VALUES (?, ?, ?, ?) ON CONFLICT ("owner_id", "channel")'
                . ' DO UPDATE SET "enabled" = excluded."enabled", "threshold" = excluded."threshold"',
                [1, 'email', true, 5],
                [Types::INTEGER, Types::STRING, Types::BOOLEAN, Types::INTEGER],
            ],
            [
                'INSERT INTO "settings" AS "br_target" ("owner_id", "channel") VALUES (?, ?)'
                . ' ON CONFLICT ("owner_id", "channel") DO NOTHING',
                [1, 'email'],
                [Types::INTEGER, Types::STRING],
            ],
        ], $statements->getArrayCopy());
    }

    public function testTheParameterBudgetCountsTheBoundUpdateValues(): void
    {
        [$connection, $statements] = $this->recordingConnection(new MySQLPlatform());
        $settings = new Repository($connection, $this->mapping(), parameterLimit: 3);

        $settings->upsert(self::KEY, [], ['enabled' => true]);
        try {
            $settings->upsert(self::KEY, ['enabled' => true]);
            self::fail('Two key values, a data value and its update need four parameters.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The query exceeds the parameter limit of 3.', $exception->getMessage());
        }

        self::assertCount(1, $statements);
    }

    public function testOtherPlatformsHaveNoUpsert(): void
    {
        [$connection, $statements] = $this->recordingConnection(new SQLServerPlatform());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Upserts are supported only on PostgreSQL, MySQL and SQLite.');
        try {
            (new Repository($connection, $this->mapping()))->upsert(self::KEY, ['enabled' => true]);
        } finally {
            self::assertCount(0, $statements);
        }
    }

    private function mapping(bool $generatedId = true): Mapping
    {
        return new Mapping(
            table: 'settings',
            columns: [
                'id' => Types::INTEGER,
                'owner_id' => Types::INTEGER,
                'channel' => Types::STRING,
                'enabled' => Types::BOOLEAN,
                'threshold' => Types::INTEGER,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'deleted_at' => Types::DATETIME_IMMUTABLE,
            ],
            hydrate: static fn(array $row): object => (object) $row,
            generatedId: $generatedId,
            softDeleteColumn: 'deleted_at',
        );
    }
}
