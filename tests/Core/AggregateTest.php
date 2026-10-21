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
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Solo\Repository\Filter;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsToMany;
use Solo\Repository\Repository;
use Solo\Repository\Tests\Fixtures\QueryLog;
use Solo\Repository\Translations;
use Solo\Repository\Visibility;

final class AggregateTest extends TestCase
{
    private Connection $connection;
    private QueryLog $log;
    private Repository $items;

    protected function setUp(): void
    {
        $this->log = new QueryLog();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->log)]);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $statements = [
            'CREATE TABLE items (id INTEGER PRIMARY KEY, quantity INTEGER NOT NULL, price DECIMAL(10, 2) NOT NULL, '
                . 'created_at DATETIME NOT NULL, deleted_at DATETIME)',
            'CREATE TABLE item_translations (item_id INTEGER, locale TEXT, name TEXT, UNIQUE(item_id, locale))',
            'CREATE TABLE tags (id INTEGER PRIMARY KEY, slug TEXT NOT NULL)',
            'CREATE TABLE item_tags (item_id INTEGER, tag_id INTEGER, UNIQUE(item_id, tag_id))',
            "INSERT INTO items VALUES (1, 2, '10.25', '2026-01-01 08:00:00', NULL),"
                . " (2, 5, '0.75', '2026-03-01 09:30:00', NULL),"
                . " (3, 7, '4.00', '2026-02-01 10:00:00', '2026-04-01 00:00:00')",
            "INSERT INTO item_translations VALUES (1, 'en', 'Lamp'), (2, 'en', 'Chair'), (2, 'uk', 'Стілець'),"
                . " (3, 'en', 'Table')",
            "INSERT INTO tags VALUES (1, 'sale'), (2, 'new')",
            'INSERT INTO item_tags VALUES (1, 1), (2, 2), (3, 1)',
        ];
        foreach ($statements as $statement) {
            $this->connection->executeStatement($statement);
        }
        $tags = new Mapping('tags', ['id' => Types::INTEGER, 'slug' => Types::STRING], self::forbidden(...));
        $this->items = new Repository($this->connection, new Mapping(
            'items',
            [
                'id' => Types::INTEGER,
                'quantity' => Types::INTEGER,
                'price' => Types::DECIMAL,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'deleted_at' => Types::DATETIME_IMMUTABLE,
            ],
            self::forbidden(...),
            softDeleteColumn: 'deleted_at',
            translations: new Translations('item_translations', 'item_id', ['name' => Types::STRING], 'en'),
            relations: ['tags' => new BelongsToMany(
                static fn (): Mapping => $tags,
                'item_tags',
                'item_id',
                'tag_id',
                static fn (object $item, array $tags): object => $item,
            )],
        ));
        $this->log->reset();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testSumIsConvertedByTheFieldTypeAndCoversOnlyVisibleRows(): void
    {
        $query = $this->items->read();

        self::assertSame(7, $query->sum('quantity'));
        self::assertSame('11', $query->sum('price'));
        self::assertSame(14, $query->withVisibility(Visibility::All)->sum('quantity'));
        self::assertSame(2, $query->sum('quantity', ['created_at <' => new DateTimeImmutable('2026-02-01')]));
        self::assertSame(2, $query->sum('quantity', Filter::related('tags', ['slug' => 'sale'])));
        self::assertCount(5, $this->log->queries);
    }

    public function testSumOfNoRowsIsZeroOfTheFieldType(): void
    {
        self::assertSame(0, $this->items->read()->sum('quantity', ['id' => 999]));
        self::assertSame('0', $this->items->read()->sum('price', ['id IN' => []]));
    }

    public function testMinAndMaxAreConvertedAndNullWhenNothingMatches(): void
    {
        $query = $this->items->read();

        self::assertSame(2, $query->min('quantity'));
        self::assertSame(7, $query->withVisibility(Visibility::All)->max('quantity'));
        $latest = $query->max('created_at');
        self::assertInstanceOf(DateTimeImmutable::class, $latest);
        self::assertSame('2026-03-01 09:30:00', $latest->format('Y-m-d H:i:s'));
        self::assertNull($query->max('quantity', ['id' => 999]));
        self::assertNull($query->min('created_at', ['id IN' => []]));
    }

    public function testAggregatesResolveTranslatedFieldsWithTheQueryLocale(): void
    {
        self::assertSame('Lamp', $this->items->read()->max('name'));
        self::assertSame('Chair', $this->items->read()->min('name'));
        self::assertSame('Lamp', $this->items->withLocale('uk', 'en')->min('name'));
        self::assertSame('Стілець', $this->items->withLocale('uk')->min('name'));
    }

    public function testAverageIsAFloatAndNullWhenNothingMatches(): void
    {
        self::assertSame(3.5, $this->items->read()->avg('quantity'));
        self::assertNull($this->items->read()->avg('quantity', ['id' => 999]));
    }

    public function testIncludesAreIgnoredAndInvalidFieldsFailBeforeSql(): void
    {
        self::assertSame(7, $this->items->with(['tags'])->sum('quantity'));
        self::assertCount(1, $this->log->queries);
        $this->log->reset();

        foreach (['missing', 'quantity) FROM items --', 'items.quantity'] as $field) {
            try {
                $this->items->read()->sum($field);
                self::fail('Aggregate fields must be mapped fields.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->log->queries);
            }
        }
    }

    public function testSumAndAverageRequireANumericFieldBeforeSql(): void
    {
        foreach (['sum' => 'created_at', 'avg' => 'name'] as $method => $field) {
            try {
                $this->items->read()->{$method}($field);
                self::fail('Sums and averages must reject non-numeric fields.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('numeric field', $exception->getMessage());
            }
        }
        self::assertSame([], $this->log->queries);
    }

    private static function forbidden(array $row): never
    {
        throw new RuntimeException('Aggregates must not hydrate models.');
    }
}
