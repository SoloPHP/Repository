<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use ArrayObject;
use DateTimeImmutable;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Solo\Repository\Filter;
use Solo\Repository\Mapping;
use Solo\Repository\Repository;
use Solo\Repository\Visibility;
use Stringable;

final class RepositoryTest extends TestCase
{
    private Connection $db;
    private Repository $repository;
    private AbstractLogger $log;

    protected function setUp(): void
    {
        $this->log = new class extends AbstractLogger {
            public array $sql = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (isset($context['sql']) && preg_match('/^(INSERT|UPDATE|DELETE|SELECT)\b/i', $context['sql'])) {
                    $this->sql[] = $context['sql'];
                }
            }
        };
        $config = new Configuration();
        $config->setMiddlewares([new Middleware($this->log)]);
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->db->executeStatement('CREATE TABLE items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku TEXT NOT NULL UNIQUE DEFAULT \'default\',
            amount DECIMAL(12, 2),
            active INTEGER,
            occurred_at DATETIME,
            metadata TEXT,
            deleted_at DATETIME,
            unexposed TEXT DEFAULT \'not mapped\'
        )');
        $this->repository = new Repository($this->db, $this->mapping());
        $this->log->sql = [];
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    private function mapping(bool $softDelete = false): Mapping
    {
        return new Mapping(
            table: 'items',
            columns: [
                'id' => Types::INTEGER,
                'sku' => Types::STRING,
                'amount' => Types::DECIMAL,
                'active' => Types::BOOLEAN,
                'occurred_at' => Types::DATETIME_IMMUTABLE,
                'metadata' => Types::JSON,
                'deleted_at' => Types::DATETIME_IMMUTABLE,
            ],
            hydrate: static fn(array $row): object => (object) $row,
            softDeleteColumn: $softDelete ? 'deleted_at' : null,
        );
    }

    public function testCrudDoesNotHydrateOrReadAfterWrite(): void
    {
        $id = $this->repository->insertGetId(['sku' => 'one']);
        self::assertSame(1, $id);
        self::assertCount(1, $this->log->sql);
        self::assertStringStartsWith('INSERT', $this->log->sql[0]);

        $this->log->sql = [];
        self::assertSame(1, $this->repository->update($id, ['sku' => 'updated']));
        self::assertCount(1, $this->log->sql);
        self::assertStringStartsWith('UPDATE', $this->log->sql[0]);
        self::assertSame('updated', $this->repository->read()->find($id)->sku);

        $this->log->sql = [];
        self::assertSame(1, $this->repository->delete($id));
        self::assertCount(1, $this->log->sql);
        self::assertStringStartsWith('DELETE', $this->log->sql[0]);
        self::assertNull($this->repository->read()->find($id));
    }

    public function testInsertReturnsAffectedRowsAndTypesRoundTrip(): void
    {
        $date = new DateTimeImmutable('2026-01-02 03:04:05');
        self::assertSame(1, $this->repository->insert([
            'id' => 10,
            'sku' => 'types',
            'amount' => '12.34',
            'active' => false,
            'occurred_at' => $date,
            'metadata' => ['enabled' => true, 'tags' => ['a', 'b']],
        ]));
        $item = $this->repository->read()->find(10);
        self::assertSame(10, $item->id);
        self::assertSame('12.34', $item->amount);
        self::assertFalse($item->active);
        self::assertEquals($date, $item->occurred_at);
        self::assertSame(['enabled' => true, 'tags' => ['a', 'b']], $item->metadata);
        self::assertObjectNotHasProperty('unexposed', $item);

        self::assertSame(1, $this->repository->update(10, ['amount' => null, 'active' => true]));
        $updated = $this->repository->read()->find(10);
        self::assertNull($updated->amount);
        self::assertTrue($updated->active);
        self::assertSame('types', $updated->sku);
        self::assertSame($item->metadata, $updated->metadata);
    }

    public function testEmptyInsertUsesDatabaseDefaults(): void
    {
        self::assertSame(1, $this->repository->insertGetId([]));
        self::assertSame('default', $this->repository->read()->find(1)->sku);
    }

    public function testExplicitIntegerIdIsReturnedWithMappedType(): void
    {
        self::assertSame(12, $this->repository->insertGetId(['id' => '12', 'sku' => 'manual']));
        self::assertSame(12, $this->repository->read()->find('12')->id);
    }

    public function testStringKeysAndQuotedIdentifiers(): void
    {
        $this->db->executeStatement('CREATE TABLE "order" ("key" TEXT PRIMARY KEY, "select" TEXT)');
        $repository = new Repository($this->db, new Mapping(
            table: 'order',
            primaryKey: 'key',
            generatedId: false,
            columns: ['key' => Types::STRING, 'select' => Types::STRING],
            hydrate: static fn(array $row): object => (object) $row,
        ));
        self::assertSame('a-b', $repository->insertGetId(['key' => 'a-b', 'select' => 'initial']));
        self::assertSame(1, $repository->update('a-b', ['select' => 'changed']));
        self::assertSame('changed', $repository->read()->find('a-b')->select);
        self::assertSame(1, $repository->delete('a-b'));
        self::assertFalse($repository->read()->exists());

        $this->log->sql = [];
        $this->expectException(InvalidArgumentException::class);
        try {
            $repository->insert(['select' => 'missing key']);
        } finally {
            self::assertSame([], $this->log->sql);
        }
    }

    public function testUnknownColumnsFailBeforeSql(): void
    {
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->repository->insert(['sku' => 'safe', 'sku) VALUES (?) --' => 'attack']);
        } finally {
            self::assertSame([], $this->log->sql);
        }
    }

    public function testWrittenValuesMustFitTheColumnTypeBeforeSql(): void
    {
        $id = $this->repository->insertGetId(['sku' => 'kept', 'amount' => '1.25', 'active' => true]);
        $this->log->sql = [];
        $writes = [
            [
                'Field "id" requires an integer or a canonical decimal string.',
                fn () => $this->repository->insert(['id' => '5x', 'sku' => 'id']),
            ],
            [
                'Field "active" requires a boolean, 0 or 1.',
                fn () => $this->repository->insertGetId(['sku' => 'flag', 'active' => 'false']),
            ],
            [
                'Field "sku" requires a string, an integer or a Stringable.',
                fn () => $this->repository->insertMany([['sku' => 'first'], ['sku' => 1.5]]),
            ],
            [
                'Field "amount" requires a finite number or a decimal string.',
                fn () => $this->repository->update($id, ['amount' => 'abc']),
            ],
            [
                'Field "active" requires a boolean, 0 or 1.',
                fn () => $this->repository->updateBy(['sku' => 'kept'], ['active' => 2]),
            ],
        ];
        foreach ($writes as [$message, $write]) {
            try {
                $write();
                self::fail('Expected a value the driver would coerce to be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }

        self::assertSame([], $this->log->sql);
        $item = $this->repository->read()->find($id);
        self::assertSame(['kept', '1.25', true], [$item->sku, $item->amount, $item->active]);
    }

    public function testWrittenValuesInTheirTypeFormsAreStored(): void
    {
        $sku = new class implements Stringable {
            public function __toString(): string
            {
                return 'stringable';
            }
        };
        $id = $this->repository->insertGetId(['sku' => $sku, 'amount' => 3, 'active' => '1']);
        self::assertSame(1, $this->repository->update($id, ['amount' => '2.75', 'active' => 0]));

        $item = $this->repository->read()->find($id);
        self::assertSame(['stringable', '2.75', false], [$item->sku, $item->amount, $item->active]);
    }

    #[DataProvider('invalidIds')]
    public function testInvalidIdCannotAccidentallyUpdateAnotherRow(mixed $id): void
    {
        $this->repository->insert(['id' => 0, 'sku' => 'zero']);
        $this->log->sql = [];
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->repository->update($id, ['sku' => 'wrong']);
        } finally {
            self::assertSame([], $this->log->sql);
        }
    }

    public static function invalidIds(): iterable
    {
        yield ['garbage'];
        yield ['0garbage'];
        yield ['1.5'];
        yield [''];
    }

    #[DataProvider('reservedPatches')]
    public function testReservedColumnsCannotBePatched(array $patch): void
    {
        $repository = new Repository($this->db, $this->mapping(true));
        $this->expectException(InvalidArgumentException::class);
        try {
            $repository->update(1, $patch);
        } finally {
            self::assertSame([], $this->log->sql);
        }
    }

    public static function reservedPatches(): iterable
    {
        yield [['id' => 2]];
        yield [['deleted_at' => null]];
    }

    public function testEmptyPatchAndImpossibleFilterDoNotExecuteSql(): void
    {
        self::assertSame(0, $this->repository->update(1, []));
        self::assertSame(0, $this->repository->updateBy(['id IN' => []], ['sku' => 'nobody']));
        self::assertSame(0, $this->repository->deleteBy(['id IN' => []]));
        self::assertSame([], $this->log->sql);
    }

    #[DataProvider('allRowFilters')]
    public function testAllRowWritesAreRejectedBeforeVisibilityIsAdded(array|Filter $filter): void
    {
        $repository = new Repository($this->db, $this->mapping(true));
        $this->expectException(InvalidArgumentException::class);
        try {
            $repository->deleteBy($filter);
        } finally {
            self::assertSame([], $this->log->sql);
        }
    }

    public static function allRowFilters(): iterable
    {
        yield [[]];
        yield [['id NOT IN' => []]];
        yield [Filter::any([], ['sku' => 'anything'])];
        yield [Filter::not(['id IN' => []])];
    }

    public function testEmptyUpdateFilterIsRejectedEvenWithEmptyPatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository->updateBy([], []);
    }

    public function testBulkPredicateKeepsOrGroupingAndSoftDeleteConstraint(): void
    {
        $this->repository->insertMany([
            ['sku' => 'a'],
            ['sku' => 'b'],
            ['sku' => 'c'],
        ]);
        $repository = new Repository($this->db, $this->mapping(true));
        self::assertSame(1, $repository->delete(2));
        self::assertSame(1, $repository->updateBy(Filter::any(['id' => 1], ['id' => 2]), ['active' => true]));
        self::assertTrue($repository->read()->find(1)->active);
        self::assertNull($repository->read()->withVisibility(Visibility::All)->find(2)->active);
    }

    public function testSoftDeleteRestoreAndPurge(): void
    {
        $repository = new Repository($this->db, $this->mapping(true));
        $id = $repository->insertGetId(['sku' => 'soft']);
        self::assertSame(1, $repository->delete($id));
        self::assertNull($repository->read()->find($id));
        $deleted = $repository->read()->withVisibility(Visibility::Deleted)->find($id);
        self::assertInstanceOf(DateTimeImmutable::class, $deleted->deleted_at);
        self::assertSame(0, $repository->delete($id));
        self::assertEquals(
            $deleted->deleted_at,
            $repository->read()->withVisibility(Visibility::All)->find($id)->deleted_at,
        );
        self::assertSame(0, $repository->update($id, ['sku' => 'not active']));
        self::assertSame(1, $repository->restore($id));
        self::assertSame(0, $repository->restore($id));
        self::assertNull($repository->read()->find($id)->deleted_at);
        self::assertSame(1, $repository->purge($id));
        self::assertSame(0, $repository->purge($id));
    }

    public function testRestoreRequiresSoftDeleteMapping(): void
    {
        $this->expectException(LogicException::class);
        $this->repository->restore(1);
    }

    public function testInsertManyNormalizesKeyOrderAndSplitsStatements(): void
    {
        self::assertSame(3, $this->repository->insertMany([
            ['sku' => 'a', 'active' => true],
            ['active' => false, 'sku' => 'b'],
            ['sku' => 'c', 'active' => true],
        ], batchSize: 2));
        self::assertCount(2, $this->log->sql);
        self::assertSame(['a', 'b', 'c'], array_column(
            $this->repository->read()->rows(['sku'], orderBy: ['id' => 'ASC']),
            'sku',
        ));
    }

    public function testInsertManyCapsBoundParameters(): void
    {
        $rows = [];
        for ($i = 0; $i < 1001; $i++) {
            $rows[] = ['sku' => "row-$i", 'active' => true];
        }
        $repository = new Repository($this->db, $this->mapping(), parameterLimit: 900);
        self::assertSame(1001, $repository->insertMany($rows, batchSize: 10000));
        self::assertCount(3, $this->log->sql); // floor(900 / 2) rows per statement
        self::assertSame(1001, $this->repository->read()->count());
    }

    public function testDefaultParameterLimitFollowsTheSqliteVersion(): void
    {
        $limit = version_compare($this->db->getServerVersion(), '3.32.0', '>=') ? 32766 : 900;
        $rows = [];
        for ($i = 0; $i < 1001; $i++) {
            $rows[] = ['sku' => "row-$i", 'active' => true];
        }
        self::assertSame(1001, $this->repository->insertMany($rows, batchSize: 10000));
        self::assertCount((int) ceil(1001 / intdiv($limit, 2)), $this->log->sql);
        self::assertCount(1001, $this->repository->read()->rows(['id'], ['id IN' => range(1, $limit)]));

        $this->log->sql = [];
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->repository->read()->rows(['id'], ['id IN' => range(1, $limit + 1)]);
        } finally {
            self::assertSame([], $this->log->sql);
        }
    }

    public function testParameterLimitMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Repository($this->db, $this->mapping(), parameterLimit: 0);
    }

    public function testInsertManyValidatesAllColumnSetsBeforeFirstWrite(): void
    {
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->repository->insertMany([['sku' => 'a'], ['sku' => 'b', 'active' => true]], batchSize: 1);
        } finally {
            self::assertSame([], $this->log->sql);
        }
    }

    public function testFailedLaterChunkRollsBackEarlierChunks(): void
    {
        try {
            $this->repository->insertMany([['sku' => 'a'], ['sku' => 'b'], ['sku' => 'a']], batchSize: 1);
            self::fail('Expected a unique constraint violation.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(0, $this->repository->read()->count());
            self::assertFalse($this->db->isTransactionActive());
        }
    }

    public function testFailedBatchUsesSavepointInsideOuterTransaction(): void
    {
        $this->db->transactional(function (): void {
            $this->repository->insert(['sku' => 'outer']);
            try {
                $this->repository->insertMany([['sku' => 'inner'], ['sku' => 'outer']], batchSize: 1);
                self::fail('Expected a unique constraint violation.');
            } catch (UniqueConstraintViolationException) {
                self::assertTrue($this->db->isTransactionActive());
                self::assertFalse($this->repository->read()->exists(['sku' => 'inner']));
            }
        });
        self::assertSame(['outer'], array_column($this->repository->read()->rows(['sku']), 'sku'));
    }

    public function testCallerTransactionRollsBackSingleWrites(): void
    {
        try {
            $this->db->transactional(function (): void {
                $this->repository->insert(['sku' => 'rolled-back']);
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException $error) {
            self::assertSame('abort', $error->getMessage());
        }
        self::assertSame(0, $this->repository->read()->count());
    }

    public function testEmptyBatchIsNoOpAndInvalidBatchSizeFails(): void
    {
        self::assertSame(0, $this->repository->insertMany([]));
        self::assertSame([], $this->log->sql);
        $this->expectException(InvalidArgumentException::class);
        $this->repository->insertMany([], batchSize: 0);
    }

    public function testPostgresGeneratedIdUsesReturningAndFreesResult(): void
    {
        $result = $this->createMock(Result::class);
        $result->expects(self::once())->method('fetchOne')->willReturn('42');
        $result->expects(self::once())->method('free');
        $connection = $this->createMock(Connection::class);
        $platform = new PostgreSQLPlatform();
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::never())->method('lastInsertId');
        $connection->expects(self::once())->method('executeQuery')->with(
            'INSERT INTO "items" ("sku") VALUES (?) RETURNING "id"',
            ['postgres'],
            [Types::STRING],
        )->willReturn($result);

        $repository = new Repository($connection, $this->mapping());
        self::assertSame(42, $repository->insertGetId(['sku' => 'postgres']));
    }

    public function testMysqlZeroIdIsRejectedBeforeItCanBecomeADifferentGeneratedId(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->expects(self::never())->method('executeStatement');
        $repository = new Repository($connection, $this->mapping());

        $this->expectException(InvalidArgumentException::class);
        $repository->insertGetId(['id' => 0, 'sku' => 'mysql']);
    }

    public function testLockForUpdateRequiresATransactionAndLocksDistinctKeysInOrder(): void
    {
        try {
            $this->repository->lockForUpdate(1);
            self::fail('Row locks outside a transaction end immediately.');
        } catch (LogicException) {
            self::assertSame([], $this->log->sql);
        }

        $this->db->transactional(function (): void {
            $this->repository->lockForUpdate([3, '1', 1]);
            $this->repository->lockForUpdate([]);
        });
        self::assertSame(['SELECT "id" FROM "items" WHERE "id" IN (?, ?) ORDER BY "id"'], $this->log->sql);
    }

    #[DataProvider('invalidLockIds')]
    public function testInvalidLockIdsAreRejectedBeforeSql(mixed $ids): void
    {
        $this->db->beginTransaction();
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->repository->lockForUpdate($ids);
        } finally {
            self::assertSame([], $this->log->sql);
            $this->db->rollBack();
        }
    }

    public static function invalidLockIds(): iterable
    {
        yield 'noncanonical' => ['01'];
        yield 'nested' => [[[1]]];
        yield 'associative' => [['id' => 1]];
        yield 'null' => [[null]];
    }

    public function testLockForUpdateUsesTheServerRowLockAndSplitsSortedKeySets(): void
    {
        [$connection, $statements] = $this->lockingConnection();

        // Sorting before the split keeps one key order across statements.
        (new Repository($connection, $this->mapping(), parameterLimit: 2))->lockForUpdate([3, 1, 2]);

        self::assertSame([
            ['SELECT "id" FROM "items" WHERE "id" IN (:dcValue1, :dcValue2) ORDER BY "id" FOR UPDATE', [1, 2]],
            ['SELECT "id" FROM "items" WHERE "id" IN (:dcValue1) ORDER BY "id" FOR UPDATE', [3]],
        ], $statements->getArrayCopy());
    }

    public function testLockForUpdateCapsKeysPerStatementBelowThePlatformBudget(): void
    {
        [$connection, $statements] = $this->lockingConnection();

        // A longer IN list can make MySQL scan, and lock, the whole table.
        (new Repository($connection, $this->mapping()))->lockForUpdate(range(5001, 1, -1));

        self::assertSame([range(1, 5000), [5001]], array_column($statements->getArrayCopy(), 1));
    }

    /** @return array{Connection, ArrayObject<int, array{string, list<mixed>}>} */
    private function lockingConnection(): array
    {
        $statements = new ArrayObject();
        $result = $this->createStub(Result::class);
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('createQueryBuilder')->willReturnCallback(
            static fn() => new \Doctrine\DBAL\Query\QueryBuilder($connection),
        );
        $connection->method('executeQuery')->willReturnCallback(
            static function (string $query, array $parameters) use ($statements, $result): Result {
                $statements[] = [$query, array_values($parameters)];
                return $result;
            },
        );

        return [$connection, $statements];
    }

    public function testPurgeByDeletesPhysicallyRegardlessOfVisibility(): void
    {
        $repository = new Repository($this->db, $this->mapping(true));
        $repository->insertMany([['sku' => 'a', 'active' => true], ['sku' => 'b', 'active' => true]]);
        $repository->delete(1);

        self::assertSame(2, $repository->purgeBy(['active' => true]));
        self::assertSame(0, $repository->read()->withVisibility(Visibility::All)->count());

        $this->log->sql = [];
        $this->expectException(InvalidArgumentException::class);
        try {
            $repository->purgeBy([]);
        } finally {
            self::assertSame([], $this->log->sql);
        }
    }
}
