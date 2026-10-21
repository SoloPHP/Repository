<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Solo\Repository\Filter;
use Solo\Repository\Mapping;
use Solo\Repository\ReadQuery;
use Solo\Repository\Repository;
use Solo\Repository\Visibility;
use Stringable;
use WeakReference;

final class ReadQueryTest extends TestCase
{
    private Connection $connection;
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $queries = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $this->queries[] = $context['sql'];
                }
            }
        };
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->logger)]);
        $this->connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            $configuration,
        );
        $this->connection->executeStatement(
            'CREATE TABLE items ('
            . 'id INTEGER PRIMARY KEY, name TEXT NOT NULL, enabled BOOLEAN NOT NULL, '
            . 'price DECIMAL(10, 2), created_at DATETIME, deleted_at DATETIME, extra TEXT)'
        );
        foreach ([-7, 0, 3, 11, 20, 80] as $id) {
            $this->connection->insert('items', [
                'id' => $id,
                'name' => 'item-' . $id,
                'enabled' => $id !== 20 ? 1 : 0,
                'price' => '12.50',
                'created_at' => '2026-01-02 03:04:05',
                'deleted_at' => $id === 11 ? '2026-02-03 04:05:06' : null,
                'extra' => 'not mapped',
            ]);
        }
        $this->logger->queries = [];
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testFindAndFindOneHaveOneLimitedSelectAndOnlyMappedColumns(): void
    {
        $query = $this->query($this->mapping());
        self::assertSame(0, $query->find('0')->id);
        self::assertCount(1, $this->logger->queries);
        self::assertStringContainsString('LIMIT 1', $this->logger->queries[0]);
        self::assertStringNotContainsString('*', $this->logger->queries[0]);
        self::assertStringNotContainsString('extra', $this->logger->queries[0]);

        $this->logger->queries = [];
        self::assertSame(80, $query->findOneBy([], ['id' => 'DESC'])->id);
        self::assertCount(1, $this->logger->queries);
        self::assertStringContainsString('LIMIT 1', $this->logger->queries[0]);
        self::assertNull($query->find(999));
        self::assertNull($query->find(11));
    }

    public function testVisibilityBranchesRemainIndependentAndReusable(): void
    {
        $active = $this->query($this->mapping());
        $all = $active->withVisibility(Visibility::All);
        $deleted = $all->withVisibility(Visibility::Deleted);

        self::assertSame(5, $active->count());
        self::assertSame(1, $deleted->count());
        self::assertSame(6, $all->count());
        self::assertSame(5, $active->count());
        self::assertSame(11, $deleted->find(11)->id);
        self::assertNull($active->find(11));
        self::assertSame(11, $all->find(11)->id);
    }

    public function testRowsConvertOnlyTheProjectionWithoutHydrating(): void
    {
        $mapping = $this->mapping(static function (): object {
            throw new RuntimeException('Projection must not hydrate a model.');
        });
        $query = $this->query($mapping);
        $rows = $query->rows(['id', 'enabled', 'price', 'created_at'], ['id' => 20]);

        self::assertCount(1, $rows);
        self::assertSame(['id', 'enabled', 'price', 'created_at'], array_keys($rows[0]));
        self::assertSame(20, $rows[0]['id']);
        self::assertFalse($rows[0]['enabled']);
        self::assertIsString($rows[0]['price']);
        self::assertSame(12.5, (float) $rows[0]['price']);
        self::assertInstanceOf(DateTimeImmutable::class, $rows[0]['created_at']);
        self::assertSame('2026-01-02 03:04:05', $rows[0]['created_at']->format('Y-m-d H:i:s'));
        self::assertCount(1, $this->logger->queries);
        self::assertStringNotContainsString('"name"', $this->logger->queries[0]);
    }

    public function testHydrationReceivesConvertedValues(): void
    {
        $item = $this->query($this->mapping())->find(-7);

        self::assertSame(-7, $item->id);
        self::assertTrue($item->enabled);
        self::assertIsString($item->price);
        self::assertInstanceOf(DateTimeImmutable::class, $item->created_at);
        self::assertNull($item->deleted_at);
        self::assertObjectNotHasProperty('extra', $item);
    }

    public function testSoftDeleteIsOutsideOrGroupsAndEmptyInIsFalse(): void
    {
        $query = $this->query($this->mapping());
        $criteria = Filter::any(['id' => 11], ['id' => 0]);

        self::assertSame([0], array_column($query->rows(['id'], $criteria), 'id'));
        self::assertSame(1, $query->count($criteria));
        self::assertFalse($query->exists(['id IN' => []]));
        self::assertSame([], $query->findBy(['id IN' => []]));
    }

    public function testCountAndExistsDoNotHydrateOrRunExtraQueries(): void
    {
        $mapping = $this->mapping(static function (): object {
            throw new RuntimeException('Scalar reads must not hydrate.');
        });
        $query = $this->query($mapping);

        self::assertTrue($query->exists(['enabled' => true]));
        self::assertCount(1, $this->logger->queries);
        self::assertStringContainsString('SELECT 1', $this->logger->queries[0]);
        self::assertStringContainsString('LIMIT 1', $this->logger->queries[0]);

        $this->logger->queries = [];
        self::assertSame(4, $query->count(['enabled' => true]));
        self::assertCount(1, $this->logger->queries);
        self::assertStringContainsString('COUNT(*)', $this->logger->queries[0]);
        self::assertStringNotContainsString('LIMIT', $this->logger->queries[0]);
    }

    public function testOrderAndPaginationHaveStableExplicitSemantics(): void
    {
        $query = $this->query($this->mapping());

        self::assertSame([20, 3], array_column($query->findBy([], ['id' => 'desc'], 2, 1), 'id'));
        self::assertCount(1, $this->logger->queries);
        self::assertStringContainsString('LIMIT 2 OFFSET 1', $this->logger->queries[0]);
    }

    public function testZeroLimitReturnsWithoutSql(): void
    {
        $query = $this->query($this->mapping());

        self::assertSame([], $query->findBy(['enabled' => true], ['id' => 'ASC'], 0));
        self::assertSame([], $query->rows(['id'], limit: 0));
        self::assertSame([], $this->logger->queries);
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidReadOptionsFailBeforeSql(string $method, array $arguments): void
    {
        $query = $this->query($this->mapping());
        try {
            $query->{$method}(...$arguments);
            self::fail('Expected invalid read options to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->logger->queries);
        }
    }

    public static function invalidRequests(): array
    {
        return [
            'empty projection' => ['rows', [[]]],
            'unknown projection' => ['rows', [['extra']]],
            'invalid projection' => ['rows', [[42]]],
            'associative projection' => ['rows', [['alias' => 'id']]],
            'unknown sort column' => ['findBy', [[], ['extra' => 'ASC']]],
            'invalid sort direction' => ['findBy', [[], ['id' => 'ASC; DROP TABLE items']]],
            'invalid sort type' => ['findBy', [[], ['id' => 1]]],
            'negative limit' => ['findBy', [[], [], -1]],
            'negative offset' => ['findBy', [[], [], null, -1]],
            'unknown criteria with zero limit' => ['findBy', [['extra' => 1], [], 0]],
            'unknown sort with zero limit' => ['findBy', [[], ['extra' => 'ASC'], 0]],
            'unknown projection with zero limit' => ['rows', [['extra'], [], [], 0]],
            'invalid ID' => ['find', ['not-an-integer']],
            'invalid batch size' => ['iterateById', [[], 0]],
            'negative batch size' => ['iterateById', [[], -1]],
            'unknown keyset filter' => ['iterateById', [['extra' => 1], 2]],
            'keyset parameter reserve' => ['iterateById', [['id IN' => range(1, 900)], 2]],
        ];
    }

    public function testDeletedVisibilityRequiresSoftDeleteButAllRemainsValid(): void
    {
        $query = $this->query($this->mapping(softDelete: false));
        self::assertSame(6, $query->count());
        self::assertSame(6, $query->withVisibility(Visibility::All)->count());

        $this->expectException(InvalidArgumentException::class);
        $query->withVisibility(Visibility::Deleted);
    }

    public function testKeysetUsesDatabaseKeysWithGapsAndNegativeIdsWithoutPublicModelIds(): void
    {
        $mapping = $this->mapping(static fn(array $row): object => new class ($row['name']) {
            public function __construct(private readonly string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }
        });
        $query = $this->query($mapping);
        $iterable = $query->iterateById(Filter::any(['enabled' => true], ['id' => 20]), 2);
        self::assertSame([], $this->logger->queries);

        $names = [];
        foreach ($iterable as $item) {
            $names[] = $item->name();
        }

        self::assertSame(['item--7', 'item-0', 'item-3', 'item-20', 'item-80'], $names);
        self::assertCount(3, $this->logger->queries);
        foreach ($this->logger->queries as $sql) {
            self::assertStringContainsString('ORDER BY "br_base"."id" ASC LIMIT 2', $sql);
            self::assertStringNotContainsString('OFFSET', $sql);
            self::assertStringNotContainsString('COUNT(', $sql);
        }
        self::assertStringContainsString('"id" >', $this->logger->queries[1]);
    }

    public function testKeysetHonorsVisibilityAndExactBatchBoundary(): void
    {
        $query = $this->query($this->mapping());
        $items = iterator_to_array($query->withVisibility(Visibility::All)->iterateById(batchSize: 2), false);

        self::assertSame([-7, 0, 3, 11, 20, 80], array_column($items, 'id'));
        self::assertCount(4, $this->logger->queries);
    }

    public function testKeysetStopsAfterFirstBatchWhenConsumerStops(): void
    {
        $query = $this->query($this->mapping());
        foreach ($query->iterateById(batchSize: 2) as $item) {
            self::assertSame(-7, $item->id);
            break;
        }

        self::assertCount(1, $this->logger->queries);
        self::assertSame(5, $query->count());
    }

    public function testKeysetDoesNotRetainModelsFromCompletedBatches(): void
    {
        $query = $this->query($this->mapping())->withVisibility(Visibility::All);
        $batchReferences = [];
        $processed = 0;

        foreach ($query->iterateById(batchSize: 2) as $item) {
            if ($processed > 0 && $processed % 2 === 0) {
                foreach ($batchReferences as $reference) {
                    self::assertNull($reference->get(), 'A completed batch must not retain its models.');
                }
                $batchReferences = [];
            }

            $batchReferences[] = WeakReference::create($item);
            ++$processed;
        }

        unset($item);
        self::assertSame(6, $processed);
        foreach ($batchReferences as $reference) {
            self::assertNull($reference->get(), 'Completed iteration must release the final batch.');
        }
    }

    public function testKeysetRejectsNonIntegerKeyBeforeIteration(): void
    {
        $mapping = new Mapping(
            table: 'items',
            columns: ['name' => Types::STRING],
            hydrate: static fn(array $row): object => (object) $row,
            primaryKey: 'name',
            generatedId: false,
        );
        $query = $this->query($mapping);

        $this->expectException(InvalidArgumentException::class);
        $query->iterateById();
    }

    public function testResultIsFreedBeforeUserHydration(): void
    {
        $freed = false;
        $driverResult = $this->createMock(DriverResult::class);
        $driverResult->method('fetchAllAssociative')->willReturn([['id' => 1, 'name' => 'first']]);
        $driverResult->expects(self::once())->method('free')->willReturnCallback(
            static function () use (&$freed): void {
                $freed = true;
            }
        );
        $connection = $this->resultConnection($driverResult);
        $mapping = new Mapping(
            table: 'items',
            columns: ['id' => Types::INTEGER, 'name' => Types::STRING],
            hydrate: static function (array $row) use (&$freed): object {
                self::assertTrue($freed);
                return (object) $row;
            },
        );

        foreach ($this->query($mapping, $connection)->iterateById(batchSize: 2) as $item) {
            self::assertSame(1, $item->id);
            break;
        }
    }

    public function testResultIsFreedWhenFetchingFails(): void
    {
        $driverResult = $this->createMock(DriverResult::class);
        $driverResult->method('fetchAllAssociative')->willThrowException(new RuntimeException('Fetch failed.'));
        $driverResult->expects(self::once())->method('free');
        $query = $this->query($this->mapping(), $this->resultConnection($driverResult));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fetch failed.');
        $query->findBy();
    }

    private function mapping(?\Closure $hydrate = null, bool $softDelete = true): Mapping
    {
        return new Mapping(
            table: 'items',
            columns: [
                'id' => Types::INTEGER,
                'name' => Types::STRING,
                'enabled' => Types::BOOLEAN,
                'price' => Types::DECIMAL,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'deleted_at' => Types::DATETIME_IMMUTABLE,
            ],
            hydrate: $hydrate ?? static fn(array $row): object => (object) $row,
            softDeleteColumn: $softDelete ? 'deleted_at' : null,
        );
    }

    private function query(Mapping $mapping, ?Connection $connection = null): ReadQuery
    {
        return (new Repository($connection ?? $this->connection, $mapping, parameterLimit: 900))->read();
    }

    private function resultConnection(DriverResult $driverResult): Connection
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDatabasePlatform', 'executeQuery'])
            ->getMock();
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects(self::once())->method('executeQuery')->willReturn(new Result($driverResult, $connection));

        return $connection;
    }
}
