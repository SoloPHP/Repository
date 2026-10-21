<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
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
        $probe = 'SELECT id FROM repo_repository_items WHERE id = ? FOR UPDATE NOWAIT';

        $this->connection->beginTransaction();
        $this->repository->lockForUpdate([$id, (string) $id]);
        try {
            $other->fetchOne($probe, [$id]);
            self::fail('A second session must not lock a row held by the first.');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertTrue($this->connection->isTransactionActive());
        } finally {
            $this->connection->rollBack();
        }

        self::assertSame($id, (int) $other->fetchOne($probe, [$id]));
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
