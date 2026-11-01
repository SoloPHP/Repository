<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Repository\Mapping;
use Solo\Repository\Page;
use Solo\Repository\PageRequest;
use Solo\Repository\Repository;
use Solo\Repository\Tests\Fixtures\QueryLog;
use Solo\Repository\Visibility;

final class PageTest extends TestCase
{
    private Connection $connection;
    private QueryLog $log;

    protected function setUp(): void
    {
        $this->log = new QueryLog();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->log)]);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->connection->executeStatement(
            'CREATE TABLE items (id INTEGER PRIMARY KEY, created_at DATETIME NOT NULL, deleted_at DATETIME)',
        );
        // Three days, two of them shared by several rows; row 6 is deleted.
        $days = [1 => '01', 2 => '01', 3 => '02', 4 => '02', 5 => '02', 6 => '03', 7 => '03'];
        foreach ($days as $id => $day) {
            $this->connection->insert('items', [
                'id' => $id,
                'created_at' => '2026-01-' . $day . ' 10:00:00',
                'deleted_at' => $id === 6 ? '2026-02-01 10:00:00' : null,
            ]);
        }
        $this->log->reset();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testRequestNormalizesTheOrderAndCountsTheOffset(): void
    {
        $request = new PageRequest(3, 20, ['created_at' => 'desc', 'id' => 'asc']);

        self::assertSame(['created_at' => 'DESC', 'id' => 'ASC'], $request->orderBy);
        self::assertSame(40, $request->offset());
        self::assertSame(0, (new PageRequest(1, 1))->offset());
        self::assertSame(PHP_INT_MAX - 1, (new PageRequest(intdiv(PHP_INT_MAX, 2) + 1, 2))->offset());
    }

    /** @param array<mixed> $orderBy */
    #[DataProvider('invalidRequests')]
    public function testRequestRejectsWhatNoPageCanBe(int $page, int $perPage, array $orderBy): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PageRequest($page, $perPage, $orderBy);
    }

    public static function invalidRequests(): iterable
    {
        yield 'page zero' => [0, 10, []];
        yield 'negative page' => [-1, 10, []];
        yield 'zero size' => [1, 0, []];
        yield 'negative size' => [1, -5, []];
        yield 'offset past the integer range' => [intdiv(PHP_INT_MAX, 2) + 2, 2, []];
        // The order is normalized as every read normalizes one; its own rules are tested there.
        yield 'invalid order' => [1, 10, ['id' => 'sideways']];
    }

    /**
     * @param array<string, string> $orderBy
     * @param array<string, string> $expected
     */
    #[DataProvider('ordersEndedByTheKey')]
    public function testTheKeyEndsTheOrderInTheDirectionOfItsLastField(array $orderBy, array $expected): void
    {
        self::assertSame($expected, (new PageRequest(1, 10, $orderBy))->orderEndingWith('id'));
    }

    public static function ordersEndedByTheKey(): iterable
    {
        yield 'no order' => [[], ['id' => 'ASC']];
        yield 'ascending' => [['created_at' => 'asc'], ['created_at' => 'ASC', 'id' => 'ASC']];
        yield 'descending last' => [
            ['name' => 'ASC', 'created_at' => 'DESC'],
            ['name' => 'ASC', 'created_at' => 'DESC', 'id' => 'DESC'],
        ];
        yield 'an order naming the key' => [
            ['id' => 'DESC', 'created_at' => 'ASC'],
            ['id' => 'DESC', 'created_at' => 'ASC'],
        ];
    }

    public function testTheKeyThatEndsAnOrderMustBeAField(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PageRequest(1, 10))->orderEndingWith('id; DROP TABLE items');
    }

    /** @param array<mixed> $items */
    #[DataProvider('invalidPages')]
    public function testPageRejectsWhatNoReadReturns(array $items, int $total, int $perPage): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Page($items, $total, new PageRequest(1, $perPage));
    }

    public static function invalidPages(): iterable
    {
        yield 'items with keys' => [['a' => 1], 1, 10];
        yield 'more items than its size' => [[1, 2, 3], 3, 2];
        yield 'negative total' => [[], -1, 10];
    }

    #[DataProvider('totals')]
    public function testOfCountsOnlyWhenTheRowsSayNothingBeyondThem(int $page, int $size, int $total, int $counts): void
    {
        $calls = 0;
        $count = static function () use (&$calls): int {
            $calls++;

            return 99;
        };
        $request = new PageRequest($page, 10);
        $result = Page::of(array_fill(0, $size, 'row'), $request, $count);

        self::assertSame([$total, $counts], [$result->total, $calls]);
        self::assertSame($request, $result->request);
    }

    public static function totals(): iterable
    {
        yield 'short first page' => [1, 3, 3, 0];
        yield 'short later page' => [3, 4, 24, 0];
        yield 'empty first page' => [1, 0, 0, 0];
        yield 'full page' => [1, 10, 99, 1];
        yield 'empty page past the end' => [4, 0, 99, 1];
    }

    public function testMapReplacesTheItemsAndKeepsTheTotalAndRequest(): void
    {
        $page = new Page([1, 2], 12, new PageRequest(3, 5, ['id' => 'DESC']));
        $mapped = $page->map(static fn(int $item): string => 'item-' . $item);

        self::assertSame(['item-1', 'item-2'], $mapped->items);
        self::assertSame(12, $mapped->total);
        self::assertSame($page->request, $mapped->request);
        self::assertSame([1, 2], $page->items);
    }

    /**
     * @param array<string, string> $orderBy
     * @param list<int> $ids
     */
    #[DataProvider('orderedPages')]
    public function testTiesOfTheRequestedOrderFallToTheKey(int $page, array $orderBy, array $ids, string $sql): void
    {
        $result = $this->items()->read()->page(new PageRequest($page, 3, $orderBy));

        self::assertSame($ids, array_column($result->items, 'id'));
        self::assertStringContainsString($sql, $this->log->statements()[0]);
    }

    public static function orderedPages(): iterable
    {
        $newest = 'ORDER BY "br_base"."created_at" DESC, "br_base"."id" DESC LIMIT 3';
        yield 'newest first' => [1, ['created_at' => 'DESC'], [7, 5, 4], $newest];
        yield 'newest first, page 2' => [2, ['created_at' => 'DESC'], [3, 2, 1], $newest . ' OFFSET 3'];
        yield 'oldest first, page 2' => [
            2,
            ['created_at' => 'ASC'],
            [4, 5, 7],
            'ORDER BY "br_base"."created_at" ASC, "br_base"."id" ASC LIMIT 3 OFFSET 3',
        ];
    }

    public function testThePageCountsOnlyWhenItsRowsCannotTellTheTotal(): void
    {
        $query = $this->items()->read();

        $full = $query->page(new PageRequest(1, 4));
        self::assertSame([4, 6], [count($full->items), $full->total]);
        self::assertCount(2, $this->log->statements());
        self::assertStringContainsString('COUNT(*)', $this->log->statements()[1]);

        $this->log->reset();
        $short = $query->page(new PageRequest(2, 4));
        self::assertSame([2, 6], [count($short->items), $short->total]);
        self::assertCount(1, $this->log->statements());
    }

    public function testThePageAndItsTotalReadTheSameRows(): void
    {
        $since = ['created_at >=' => new DateTimeImmutable('2026-01-02 00:00:00')];
        $active = $this->items()->read();

        $page = $active->page(new PageRequest(1, 2), $since);
        self::assertSame([3, 4], array_column($page->items, 'id'));
        self::assertSame(4, $page->total);
        self::assertStringContainsString('"br_base"."deleted_at" IS NULL', $this->log->statements()[1]);

        $all = $active->withVisibility(Visibility::All)->page(new PageRequest(3, 2), $since);
        self::assertSame([7], array_column($all->items, 'id'));
        self::assertSame(5, $all->total);

        $none = $active->page(new PageRequest(1, 2), ['id' => 99]);
        self::assertSame([[], 0], [$none->items, $none->total]);
    }

    /** @return Repository<object> */
    private function items(): Repository
    {
        return new Repository($this->connection, new Mapping(
            table: 'items',
            columns: [
                'id' => Types::INTEGER,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'deleted_at' => Types::DATETIME_IMMUTABLE,
            ],
            hydrate: static fn(array $row): object => (object) $row,
            softDeleteColumn: 'deleted_at',
        ));
    }
}
