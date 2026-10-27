<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Repository\Mapping;
use Solo\Repository\ReadQuery;
use Solo\Repository\Relations\BelongsTo;
use Solo\Repository\Repository;
use Solo\Repository\Tests\Fixtures\QueryLog;
use Solo\Repository\Tests\Fixtures\RecordsStatements;
use Solo\Repository\Translations;
use Solo\Repository\Visibility;

final class LockingReadTest extends TestCase
{
    use RecordsStatements;

    private Connection $connection;
    private QueryLog $log;
    private Repository $items;

    protected function setUp(): void
    {
        $this->log = new QueryLog();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->log)]);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->connection->executeStatement(
            'CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
        );
        $this->connection->executeStatement(
            'CREATE TABLE items (id INTEGER PRIMARY KEY, category_id INTEGER, amount INTEGER NOT NULL, '
            . 'deleted_at DATETIME)',
        );
        $this->connection->executeStatement(
            'CREATE TABLE item_translations (item_id INTEGER NOT NULL, locale TEXT NOT NULL, name TEXT, '
            . 'PRIMARY KEY (item_id, locale))',
        );
        $this->connection->insert('categories', ['id' => 1, 'name' => 'Chairs']);
        foreach ([[1, 1, 10, null], [2, null, 20, null], [3, 1, 30, '2026-01-01 00:00:00']] as $row) {
            $this->connection->insert(
                'items',
                array_combine(['id', 'category_id', 'amount', 'deleted_at'], $row),
            );
        }
        $this->connection->insert('item_translations', ['item_id' => 1, 'locale' => 'en', 'name' => 'Chair']);
        $this->items = new Repository($this->connection, $this->mapping());
        $this->log->reset();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[DataProvider('lockingReads')]
    public function testLockingReadsNeedATransactionAndRunThePlainSelectOnSqlite(\Closure $read, mixed $expected): void
    {
        try {
            $read($this->items->read()->forUpdate());
            self::fail('A row lock outside a transaction would end with its statement.');
        } catch (LogicException $exception) {
            self::assertSame('Row locks require an active transaction.', $exception->getMessage());
            self::assertSame([], $this->log->statements());
        }

        $this->connection->beginTransaction();
        try {
            self::assertSame($expected, $read($this->items->read()->forUpdate()));
        } finally {
            $this->connection->rollBack();
        }

        self::assertNotSame([], $this->log->selects());
        foreach ($this->log->selects() as $select) {
            self::assertStringNotContainsString('FOR UPDATE', $select['sql']);
        }
    }

    public static function lockingReads(): iterable
    {
        yield 'find' => [static fn(ReadQuery $read): ?int => $read->find(1)?->id, 1];
        yield 'findOneBy' => [static fn(ReadQuery $read): ?int => $read->findOneBy(['amount' => 20])?->id, 2];
        yield 'findBy' => [
            static fn(ReadQuery $read): array => array_column($read->findBy(orderBy: ['id' => 'DESC']), 'id'),
            [2, 1],
        ];
        yield 'rows' => [
            static fn(ReadQuery $read): array => $read->rows(['amount'], ['id' => 2]),
            [['amount' => 20]],
        ];
        yield 'exists' => [static fn(ReadQuery $read): bool => $read->exists(['amount' => 10]), true];
        yield 'iterateById' => [
            static fn(ReadQuery $read): array => array_column(iterator_to_array($read->iterateById(), false), 'id'),
            [1, 2],
        ];
    }

    #[DataProvider('readsWithoutRows')]
    public function testAggregatesAndQueryBuildersCannotLock(\Closure $read, string $message): void
    {
        $this->connection->beginTransaction();
        try {
            $read($this->items->read()->forUpdate());
            self::fail('Expected the locking read to be rejected.');
        } catch (LogicException $exception) {
            self::assertStringStartsWith($message, $exception->getMessage());
            self::assertSame([], $this->log->statements());
        } finally {
            $this->connection->rollBack();
        }
    }

    public static function readsWithoutRows(): iterable
    {
        yield 'count' => [static fn(ReadQuery $read): int => $read->count(), 'Aggregates do not lock rows'];
        yield 'sum' => [static fn(ReadQuery $read): mixed => $read->sum('amount'), 'Aggregates do not lock rows'];
        yield 'min' => [static fn(ReadQuery $read): mixed => $read->min('amount'), 'Aggregates do not lock rows'];
        yield 'max' => [static fn(ReadQuery $read): mixed => $read->max('amount'), 'Aggregates do not lock rows'];
        yield 'avg' => [static fn(ReadQuery $read): ?float => $read->avg('amount'), 'Aggregates do not lock rows'];
        yield 'queryBuilder' => [
            static fn(ReadQuery $read): QueryBuilder => $read->queryBuilder('i'),
            'Query builders do not lock rows',
        ];
    }

    public function testDerivedReadsKeepTheLockAndTheOriginalStaysPlain(): void
    {
        $plain = $this->items->read();
        $locked = $plain->forUpdate();

        foreach (
            [
                $locked->withVisibility(Visibility::All),
                $locked->withLocale('en'),
                $locked->with(['category']),
            ] as $derived
        ) {
            try {
                $derived->find(1);
                self::fail('A derived read must keep the lock.');
            } catch (LogicException) {
                self::assertSame([], $this->log->statements());
            }
        }

        self::assertSame(1, $plain->find(1)?->id);
        self::assertSame(2, $plain->count());
    }

    public function testLockingReadKeepsVisibilityAndCriteria(): void
    {
        $this->connection->beginTransaction();
        try {
            $read = $this->items->read()->forUpdate();
            self::assertNull($read->find(3));
            self::assertSame(3, $read->withVisibility(Visibility::Deleted)->find(3)?->id);
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testIterationChecksTheTransactionWhenItsStatementsRun(): void
    {
        $this->connection->beginTransaction();
        $batches = $this->items->read()->forUpdate()->iterateById(batchSize: 1);
        $this->connection->rollBack();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Row locks require an active transaction.');
        iterator_to_array($batches);
    }

    #[DataProvider('lockClauses')]
    public function testServerLockClauseLocksOnlyTheReadTableWhereThePlatformCan(
        AbstractPlatform $platform,
        string $clause,
    ): void {
        [$connection, $statements] = $this->recordingConnection($platform);

        (new Repository($connection, $this->mapping()))->read()->forUpdate()->find(5);

        $sql = array_column($statements->getArrayCopy(), 0);
        self::assertCount(1, $sql);
        self::assertStringEndsWith(' LIMIT 1 ' . $clause, $sql[0]);
        self::assertSame(1, substr_count($sql[0], 'FOR UPDATE'));
        self::assertSame([5], $statements->getArrayCopy()[0][1]);
    }

    public static function lockClauses(): iterable
    {
        // PostgreSQL cannot lock the nullable side of an outer join, so the lock names the read's table.
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), 'FOR UPDATE OF "br_base"'];
        yield 'MySQL 8' => [new MySQL84Platform(), 'FOR UPDATE OF `br_base`'];
        // MariaDB and MySQL 5.7 have no OF: their lock also covers the rows the statement joins.
        yield 'MariaDB' => [new MariaDBPlatform(), 'FOR UPDATE'];
        yield 'MySQL 5.7' => [new MySQLPlatform(), 'FOR UPDATE'];
    }

    public function testTranslationAndOrderJoinsStayOutsideThePostgresLock(): void
    {
        [$connection, $statements] = $this->recordingConnection(new PostgreSQLPlatform());

        (new Repository($connection, $this->mapping()))->withLocale('uk', 'en')->forUpdate()
            ->findBy(['amount >' => 0], ['category.name' => 'ASC', 'name' => 'DESC']);

        $sql = $statements->getArrayCopy()[0][0];
        self::assertStringContainsString('LEFT JOIN "item_translations" br_base_translation', $sql);
        self::assertStringContainsString('LEFT JOIN "item_translations" br_base_fallback', $sql);
        self::assertStringContainsString('LEFT JOIN "categories" br_base_order1', $sql);
        self::assertStringEndsWith(' FOR UPDATE OF "br_base"', $sql);
        self::assertSame(1, substr_count($sql, 'FOR UPDATE'));
    }

    public function testExistsLocksTheRowItFindsAndRelationLoadsReadWithoutLocks(): void
    {
        $item = ['id' => 1, 'category_id' => 7, 'amount' => 10, 'deleted_at' => null, 'name' => null];
        [$connection, $statements] = $this->recordingConnection(new PostgreSQLPlatform(), [[$item], [$item]]);
        $read = (new Repository($connection, $this->mapping()))->read()->forUpdate();

        self::assertTrue($read->exists(['amount' => 10]));
        self::assertSame([1], array_column($read->with(['category'])->findBy(['id' => 1]), 'id'));

        $sql = array_column($statements->getArrayCopy(), 0);
        self::assertCount(3, $sql);
        self::assertStringStartsWith('SELECT 1 FROM "items" br_base', $sql[0]);
        self::assertStringEndsWith(' LIMIT 1 FOR UPDATE OF "br_base"', $sql[0]);
        self::assertStringEndsWith(' FOR UPDATE OF "br_base"', $sql[1]);
        self::assertStringContainsString(' FROM "categories" br_base', $sql[2]);
        self::assertStringNotContainsString('FOR UPDATE', $sql[2]);
    }

    private function mapping(): Mapping
    {
        $categories = new Mapping(
            table: 'categories',
            columns: ['id' => Types::INTEGER, 'name' => Types::STRING],
            hydrate: static fn(array $row): object => (object) $row,
        );

        return new Mapping(
            table: 'items',
            columns: [
                'id' => Types::INTEGER,
                'category_id' => Types::INTEGER,
                'amount' => Types::INTEGER,
                'deleted_at' => Types::DATETIME_IMMUTABLE,
            ],
            hydrate: static fn(array $row): object => (object) $row,
            softDeleteColumn: 'deleted_at',
            translations: new Translations('item_translations', 'item_id', ['name' => Types::STRING]),
            relations: [
                'category' => new BelongsTo(
                    static fn(): Mapping => $categories,
                    'category_id',
                    static function (object $item, ?object $category): object {
                        $item->category = $category;

                        return $item;
                    },
                ),
            ],
        );
    }
}
