<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\Types;
use Solo\Repository\Like;
use Solo\Repository\Mapping;
use Solo\Repository\Repository;
use Solo\Repository\Visibility;

final class RepositoryDatabaseTest extends DatabaseTestCase
{
    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $schema = new Schema();
        $items = $schema->createTable('repo_repository_items');
        $items->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $items->setPrimaryKey(['id']);
        $items->addColumn('sku', Types::STRING, ['length' => 100, 'default' => 'from-default']);
        $items->addUniqueIndex(['sku'], 'repo_repository_items_sku_unique');
        $items->addColumn('amount', Types::DECIMAL, ['precision' => 12, 'scale' => 2, 'notnull' => false]);
        $items->addColumn('active', Types::BOOLEAN, ['notnull' => false]);
        $items->addColumn('occurred_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $items->addColumn('metadata', Types::JSON, ['notnull' => false]);
        $items->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $this->createSchema($schema);

        $this->repository = new Repository($this->connection, $this->mapping());
        $this->log->reset();
    }

    public function testCrudReturnsTypedGeneratedAndExplicitIdsWithoutHiddenReads(): void
    {
        $id = $this->repository->insertGetId(['sku' => 'generated']);
        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);
        $explicitId = $id + 1000;
        self::assertSame($explicitId, $this->repository->insertGetId([
            'id' => (string) $explicitId,
            'sku' => 'explicit',
        ]));
        self::assertSame(1, $this->repository->insert(['sku' => 'affected']));
        self::assertSame(1, $this->repository->update($id, ['sku' => 'updated']));
        self::assertSame(1, $this->repository->delete($explicitId));
        self::assertSame([], $this->log->selects());

        self::assertSame('updated', $this->repository->read()->find((string) $id)->sku);
        self::assertNull($this->repository->read()->find($explicitId));
        self::assertSame(2, $this->repository->read()->count());
    }

    public function testEmptyInsertUsesDatabaseDefaultAndReturnsGeneratedId(): void
    {
        $id = $this->repository->insertGetId([]);

        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);
        self::assertCount(1, $this->log->queries);
        self::assertStringStartsWith('INSERT', $this->log->queries[0]['sql']);
        self::assertSame('from-default', $this->repository->read()->find($id)->sku);
    }

    public function testDecimalBooleanDateAndJsonRoundTripAndPatchKeepsOmittedFields(): void
    {
        $date = new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('UTC'));
        $id = $this->repository->insertGetId([
            'sku' => 'typed',
            'amount' => '12.30',
            'active' => false,
            'occurred_at' => $date,
            'metadata' => ['enabled' => true, 'tags' => ['a', 'b'], 'label' => 'Стіл'],
        ]);
        $item = $this->repository->read()->find($id);

        self::assertSame('12.30', $item->amount);
        self::assertFalse($item->active);
        self::assertInstanceOf(DateTimeImmutable::class, $item->occurred_at);
        self::assertSame('2026-01-02 03:04:05', $item->occurred_at->format('Y-m-d H:i:s'));
        self::assertTrue($item->metadata['enabled']);
        self::assertSame(['a', 'b'], $item->metadata['tags']);
        self::assertSame('Стіл', $item->metadata['label']);

        self::assertSame(1, $this->repository->update($id, ['amount' => null, 'active' => true]));
        $updated = $this->repository->read()->find($id);
        self::assertNull($updated->amount);
        self::assertTrue($updated->active);
        self::assertSame('typed', $updated->sku);
        self::assertSame($item->metadata, $updated->metadata);
        self::assertEquals($item->occurred_at, $updated->occurred_at);
    }

    public function testTypedInListsMatchOnTheServer(): void
    {
        $utc = new DateTimeZone('UTC');
        $dates = [
            new DateTimeImmutable('2026-01-02 03:04:05', $utc),
            new DateTimeImmutable('2026-01-03 03:04:05', $utc),
            new DateTimeImmutable('2026-01-04 03:04:05', $utc),
        ];
        $this->repository->insertMany([
            ['sku' => 'a', 'amount' => '1.50', 'active' => true, 'occurred_at' => $dates[0]],
            ['sku' => 'b', 'amount' => '2.00', 'active' => false, 'occurred_at' => $dates[1]],
            ['sku' => 'c', 'amount' => '3.25', 'active' => true, 'occurred_at' => $dates[2]],
        ]);
        $read = $this->repository->read();
        $skus = static fn (array $criteria): array => array_column(
            $read->rows(['sku'], $criteria, ['sku' => 'ASC']),
            'sku',
        );

        self::assertSame(['a', 'c'], $skus(['occurred_at IN' => [$dates[0], $dates[2]]]));
        self::assertSame(['b'], $skus(['amount IN' => ['2.00', '9.99']]));
        self::assertSame(['a', 'c'], $skus(['sku NOT IN' => ['b', 'z'], 'active IN' => [true]]));
    }

    public function testLikePatternsEscapeWithABackslashOnTheServer(): void
    {
        $schema = new Schema();
        $patterns = $schema->createTable('repo_repository_patterns');
        $patterns->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $patterns->setPrimaryKey(['id']);
        $patterns->addColumn('code', Types::STRING, ['length' => 20]);
        // A single-byte character set on MySQL and MariaDB; PostgreSQL ignores the option.
        $patterns->addColumn('latin', Types::STRING, ['length' => 20, 'platformOptions' => ['charset' => 'latin1']]);
        $this->createSchema($schema);
        $repository = new Repository($this->connection, new Mapping(
            table: 'repo_repository_patterns',
            columns: ['id' => Types::INTEGER, 'code' => Types::STRING, 'latin' => Types::STRING],
            hydrate: static fn(array $row): object => (object) $row,
        ));
        $repository->insertMany(array_map(
            static fn (string $value): array => ['code' => $value, 'latin' => $value],
            ['50%', '50x', 'a_b', 'axb', 'C:\\dir', 'C:dir', 'end\\'],
        ));
        $read = $repository->read();
        $assertMatches = static function () use ($read): void {
            foreach (['code', 'latin'] as $field) {
                $values = static fn (string $operator, string $pattern): array => array_column(
                    $read->rows([$field], [$field . ' ' . $operator => $pattern], ['id' => 'ASC']),
                    $field,
                );
                self::assertSame(['50%', '50x'], $values('LIKE', '50%'));
                self::assertSame(['50%'], $values('LIKE', Like::startsWith('50%')));
                self::assertSame(['a_b'], $values('LIKE', Like::escape('a_b')));
                self::assertSame(['C:\\dir'], $values('LIKE', 'C:\\\\dir'));
                self::assertSame(['C:dir'], $values('LIKE', 'C:\\dir'));
                self::assertSame(['C:\\dir', 'end\\'], $values('LIKE', Like::contains('\\')));
                self::assertSame(['end\\'], $values('LIKE', Like::endsWith('\\')));
                self::assertSame(['50%', '50x', 'a_b', 'axb', 'C:dir'], $values('NOT LIKE', Like::contains('\\')));
            }
        };

        $assertMatches();
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            // In this mode MySQL has no default LIKE escape, MariaDB none for single-byte character
            // sets, and both read the string '\\' as two characters.
            $mode = $this->connection->fetchOne('SELECT @@SESSION.sql_mode');
            $this->connection->executeStatement(
                "SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',NO_BACKSLASH_ESCAPES')",
            );
            try {
                $assertMatches();
            } finally {
                $this->connection->executeStatement('SET SESSION sql_mode = ?', [$mode]);
            }
        }
    }

    public function testLikeMatchesBinaryFieldsOnTheServer(): void
    {
        $schema = new Schema();
        $files = $schema->createTable('repo_repository_files');
        $files->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $files->setPrimaryKey(['id']);
        $files->addColumn('payload', Types::BINARY, ['length' => 20]);
        $this->createSchema($schema);
        $repository = new Repository($this->connection, new Mapping(
            table: 'repo_repository_files',
            columns: ['id' => Types::INTEGER, 'payload' => Types::BINARY],
            hydrate: static fn(array $row): object => (object) $row,
        ));
        $repository->insertMany([['payload' => '50%'], ['payload' => '50x']]);

        // PostgreSQL escapes with "\" without an ESCAPE clause, which would make it compare bytea with text.
        self::assertSame(2, $repository->read()->count(['payload LIKE' => '50%']));
        self::assertSame(1, $repository->read()->count(['payload LIKE' => Like::startsWith('50%')]));
    }

    public function testSoftDeleteVisibilityRestorePurgeAndFilteredKeyset(): void
    {
        $repository = new Repository($this->connection, $this->mapping(true));
        self::assertSame(4, $repository->insertMany([
            ['sku' => 'first', 'active' => true],
            ['sku' => 'deleted', 'active' => true],
            ['sku' => 'inactive', 'active' => false],
            ['sku' => 'last', 'active' => true],
        ]));
        $ids = array_column($repository->read()->rows(['id'], orderBy: ['id' => 'ASC']), 'id');

        self::assertSame(1, $repository->delete($ids[1]));
        self::assertNull($repository->read()->find($ids[1]));
        $deleted = $repository->read()->withVisibility(Visibility::Deleted)->find($ids[1]);
        self::assertInstanceOf(DateTimeImmutable::class, $deleted->deleted_at);
        self::assertSame(0, $repository->delete($ids[1]));
        self::assertEquals(
            $deleted->deleted_at,
            $repository->read()->withVisibility(Visibility::All)->find($ids[1])->deleted_at,
        );
        self::assertSame(0, $repository->update($ids[1], ['sku' => 'hidden']));

        $items = iterator_to_array($repository->read()->iterateById(['active' => true], batchSize: 1));
        self::assertSame([$ids[0], $ids[3]], array_column($items, 'id'));
        self::assertSame(1, $repository->restore($ids[1]));
        self::assertSame(0, $repository->restore($ids[1]));
        self::assertNull($repository->read()->find($ids[1])->deleted_at);
        self::assertSame(1, $repository->delete($ids[1]));
        self::assertSame(1, $repository->purge($ids[1]));
        self::assertNull($repository->read()->withVisibility(Visibility::All)->find($ids[1]));
    }

    public function testParameterBudgetSplitsBulkAndLateFailureRollsBackEarlierChunk(): void
    {
        $rows = [];
        for ($index = 0; $index < 450; $index++) {
            $rows[] = ['sku' => 'item-' . $index, 'active' => true];
        }
        $rows[] = ['sku' => 'item-0', 'active' => false];
        $repository = new Repository($this->connection, $this->mapping(), parameterLimit: 900);

        try {
            $repository->insertMany($rows, batchSize: 1000);
            self::fail('Expected a unique violation in the second SQL chunk.');
        } catch (UniqueConstraintViolationException) {
            $inserts = array_values(array_filter(
                $this->log->queries,
                static fn(array $query): bool => str_starts_with($query['sql'], 'INSERT'),
            ));
            self::assertCount(2, $inserts);
            self::assertCount(900, $inserts[0]['params']);
            self::assertCount(2, $inserts[1]['params']);
            self::assertFalse($this->connection->isTransactionActive());
            self::assertSame(0, $this->repository->read()->count());
        }
    }

    public function testBulkFailureRollsBackSavepointAndCallerCanContinueAndCommit(): void
    {
        $this->connection->transactional(function (): void {
            $this->repository->insert(['sku' => 'outer-before']);
            try {
                $this->repository->insertMany([
                    ['sku' => 'inner'],
                    ['sku' => 'outer-before'],
                ], batchSize: 1);
                self::fail('Expected a unique violation inside the savepoint.');
            } catch (UniqueConstraintViolationException) {
                self::assertTrue($this->connection->isTransactionActive());
                self::assertFalse($this->repository->read()->exists(['sku' => 'inner']));
            }
            $this->repository->insert(['sku' => 'outer-after']);
        });

        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame(
            ['outer-before', 'outer-after'],
            array_column($this->repository->read()->rows(['sku'], orderBy: ['id' => 'ASC']), 'sku'),
        );
    }

    public function testLockForUpdateHoldsRowsUntilTheTransactionEnds(): void
    {
        $id = $this->repository->insertGetId(['sku' => 'locked']);
        $other = $this->otherConnection();
        $probe = 'SELECT id FROM repo_repository_items WHERE id = ?';

        $this->connection->beginTransaction();
        try {
            $this->repository->lockForUpdate([$id, (string) $id]);
            self::assertTrue(self::isLocked($other, $probe, [$id]));
        } finally {
            $this->connection->rollBack();
        }

        self::assertFalse(self::isLocked($other, $probe, [$id]));
    }

    public function testLockingReadHoldsTheRowsItReadsUntilTheTransactionEnds(): void
    {
        $ids = [
            $this->repository->insertGetId(['sku' => 'read']),
            $this->repository->insertGetId(['sku' => 'existing']),
            $this->repository->insertGetId(['sku' => 'untouched']),
        ];
        $other = $this->otherConnection();
        $probe = 'SELECT id FROM repo_repository_items WHERE id = ?';

        $this->connection->beginTransaction();
        try {
            $read = $this->repository->read()->forUpdate();
            self::assertSame('read', $read->find($ids[0])->sku);
            self::assertTrue($read->exists(['sku' => 'existing']));
            self::assertTrue(self::isLocked($other, $probe, [$ids[0]]));
            self::assertTrue(self::isLocked($other, $probe, [$ids[1]]));
            self::assertFalse(self::isLocked($other, $probe, [$ids[2]]));
            self::assertStringContainsString('FOR UPDATE', $this->log->selects()[0]['sql']);
        } finally {
            $this->connection->rollBack();
        }

        self::assertFalse(self::isLocked($other, $probe, [$ids[0]]));
    }

    public function testLockingReadNeverAnswersWithTheSnapshotOfAPlainRead(): void
    {
        $id = $this->repository->insertGetId(['sku' => 'snapshot', 'amount' => '1.00']);
        $other = $this->otherConnection();
        $platform = $this->connection->getDatabasePlatform();
        // PostgreSQL, and MariaDB with snapshot isolation (its default since 11.6), refuse to lock
        // a row changed since the snapshot; MySQL reads the committed row.
        $postgres = $platform instanceof PostgreSQLPlatform;
        $refuses = $postgres || (
            $platform instanceof MariaDBPlatform
            && (int) $this->connection->fetchOne('SELECT @@innodb_snapshot_isolation') === 1
        );

        $this->connection->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
        $this->connection->beginTransaction();
        try {
            // The first plain read takes the snapshot the transaction then reads.
            self::assertSame('1.00', $this->repository->read()->find($id)->amount);
            $other->executeStatement('UPDATE repo_repository_items SET amount = ? WHERE id = ?', ['2.00', $id]);
            self::assertSame('1.00', $this->repository->read()->find($id)->amount);

            try {
                $locked = $this->repository->read()->forUpdate()->find($id);
                self::assertFalse($refuses, 'Expected a serialization failure.');
                self::assertSame('2.00', $locked->amount);
            } catch (DriverException $exception) {
                // The transaction has to start over; a READ COMMITTED one reads the new row instead.
                self::assertTrue($refuses, $exception->getMessage());
                self::assertTrue($postgres ? $exception instanceof DeadlockException : $exception->getCode() === 1020);
            }
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testUpsertInsertsOrUpdatesTheRowOfAUniqueKeyOnTheServer(): void
    {
        $repository = new Repository($this->connection, $this->mapping(true));

        $repository->upsert(['sku' => 'setting'], ['amount' => '1.00'], ['active' => true]);
        $id = $repository->read()->findOneBy(['sku' => 'setting'])->id;
        $repository->upsert(['sku' => 'setting'], ['amount' => '2.50'], ['active' => false]);
        $repository->upsert(['sku' => 'setting'], [], ['amount' => '9.00']);
        $row = $repository->read()->find($id);
        self::assertSame('2.50', $row->amount);
        self::assertTrue($row->active);
        self::assertSame(1, $repository->read()->count());

        // By primary key; a soft-deleted row is still the one holding its key.
        $repository->upsert(['id' => $id], ['metadata' => ['by' => 'id']]);
        $repository->delete($id);
        $repository->upsert(['sku' => 'setting'], ['amount' => '3.00']);
        self::assertNull($repository->read()->find($id));
        $deleted = $repository->read()->withVisibility(Visibility::Deleted)->find($id);
        self::assertSame('3.00', $deleted->amount);
        self::assertSame(['by' => 'id'], $deleted->metadata);

        $upserts = array_values(array_filter(
            $this->log->statements(),
            static fn(string $sql): bool => str_starts_with($sql, 'INSERT'),
        ));
        self::assertCount(5, $upserts);
        self::assertSame(1, $repository->read()->withVisibility(Visibility::All)->count());
    }

    public function testAggregatesAreTypedByTheFieldOnTheServer(): void
    {
        $repository = new Repository($this->connection, $this->mapping(true));
        $repository->insertMany([
            ['sku' => 'a', 'amount' => '12.30', 'occurred_at' => new DateTimeImmutable('2026-01-02 03:04:05')],
            ['sku' => 'b', 'amount' => '0.70', 'occurred_at' => new DateTimeImmutable('2026-03-04 05:06:07')],
            ['sku' => 'c', 'amount' => '5.00', 'occurred_at' => null],
        ]);
        $repository->deleteBy(['sku' => 'c']);
        $query = $repository->read();

        self::assertSame('13.00', $query->sum('amount'));
        self::assertSame('0.70', $query->min('amount'));
        self::assertSame(6.5, $query->avg('amount'));
        self::assertSame('2026-03-04 05:06:07', $query->max('occurred_at')?->format('Y-m-d H:i:s'));
        self::assertSame('18.00', $query->withVisibility(Visibility::All)->sum('amount'));
        self::assertSame('0', $query->sum('amount', ['sku' => 'missing']));
        self::assertNull($query->max('occurred_at', ['sku' => 'missing']));
        self::assertNull($query->avg('amount', ['sku' => 'missing']));
    }

    public function testQueryBuilderMatchesUnquotedAliasReferencesOnTheServer(): void
    {
        $repository = new Repository($this->connection, $this->mapping(true));
        $repository->insertMany([
            ['sku' => 'a', 'amount' => '12.30', 'active' => true],
            ['sku' => 'b', 'amount' => '0.70', 'active' => true],
            ['sku' => 'c', 'amount' => '5.00', 'active' => false],
            ['sku' => 'd', 'amount' => '9.00', 'active' => true],
        ]);
        $repository->deleteBy(['sku' => 'd']);

        $query = $repository->read()->queryBuilder('i', ['active' => true]);
        $row = $query->select('COUNT(*) AS items', 'SUM(i.amount) AS total')
            ->andWhere('i.sku <> ' . $query->createNamedParameter('b'))
            ->fetchAssociative();

        self::assertSame(1, (int) $row['items']);
        self::assertSame('12.30', number_format((float) $row['total'], 2, '.', ''));
    }

    private function mapping(bool $softDelete = false): Mapping
    {
        return new Mapping(
            table: 'repo_repository_items',
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
}
