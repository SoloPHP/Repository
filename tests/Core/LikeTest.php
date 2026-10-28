<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Closure;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Repository\Filter;
use Solo\Repository\Like;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\HasMany;
use Solo\Repository\Repository;
use Solo\Repository\Tests\Fixtures\QueryLog;
use Solo\Repository\Translations;
use Stringable;

final class LikeTest extends TestCase
{
    private Connection $connection;
    private QueryLog $log;
    private Repository $items;

    protected function setUp(): void
    {
        $this->log = new QueryLog();
        $this->connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            (new Configuration())->setMiddlewares([new Middleware($this->log)]),
        );
        $this->connection->executeStatement('CREATE TABLE items (id INTEGER PRIMARY KEY, code TEXT NOT NULL)');
        $this->connection->executeStatement(
            'CREATE TABLE item_translations (item_id INTEGER NOT NULL, locale TEXT NOT NULL, title TEXT, '
            . 'UNIQUE(item_id, locale))',
        );
        $this->connection->executeStatement(
            'CREATE TABLE tags (id INTEGER PRIMARY KEY, item_id INTEGER NOT NULL, label TEXT NOT NULL)',
        );
        $codes = [1 => '50%', 2 => '50x', 3 => 'a_b', 4 => 'axb', 5 => 'C:\\dir', 6 => 'C:dir', 7 => 'end\\'];
        foreach ($codes as $id => $code) {
            $this->connection->insert('items', ['id' => $id, 'code' => $code]);
        }
        $titles = [
            [1, 'en', '100% cotton'],
            [2, 'uk', 'Знижка 50%'],
            [2, 'en', 'Sale'],
            [3, 'uk', 'a_b'],
            [3, 'en', '50%'],
        ];
        foreach ($titles as [$item, $locale, $title]) {
            $this->connection->insert('item_translations', [
                'item_id' => $item,
                'locale' => $locale,
                'title' => $title,
            ]);
        }
        foreach ([[1, 3, 'sale_50%'], [2, 4, 'sale 50x']] as [$id, $item, $label]) {
            $this->connection->insert('tags', ['id' => $id, 'item_id' => $item, 'label' => $label]);
        }
        $tags = new Mapping(
            'tags',
            ['id' => Types::INTEGER, 'item_id' => Types::INTEGER, 'label' => Types::STRING],
            static fn (array $row): object => (object) $row,
        );
        $this->items = new Repository($this->connection, new Mapping(
            table: 'items',
            columns: ['id' => Types::INTEGER, 'code' => Types::STRING],
            hydrate: static fn (array $row): object => (object) $row,
            translations: new Translations('item_translations', 'item_id', ['title' => Types::STRING]),
            relations: ['tags' => new HasMany(static fn (): Mapping => $tags, 'item_id')],
        ));
        $this->log->reset();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[DataProvider('texts')]
    public function testPatternsMatchTheTextLiterally(string $text, string $escaped): void
    {
        self::assertSame($escaped, Like::escape($text));
        self::assertSame('%' . $escaped . '%', Like::contains($text));
        self::assertSame($escaped . '%', Like::startsWith($text));
        self::assertSame('%' . $escaped, Like::endsWith($text));
    }

    public static function texts(): iterable
    {
        yield 'plain' => ['Chair', 'Chair'];
        yield 'empty' => ['', ''];
        yield 'percent' => ['50%', '50\\%'];
        yield 'underscore' => ['a_b', 'a\\_b'];
        yield 'backslash' => ['C:\\dir', 'C:\\\\dir'];
        yield 'escaped wildcard' => ['\\%', '\\\\\\%'];
        yield 'multibyte' => ['Стіл_100%', 'Стіл\\_100\\%'];
    }

    #[DataProvider('patterns')]
    public function testLikeEscapesWithABackslash(array|Filter $criteria, array $ids): void
    {
        self::assertSame($ids, array_column($this->items->read()->rows(['id'], $criteria, ['id' => 'ASC']), 'id'));
        self::assertStringContainsString("LIKE ? ESCAPE '\\'", $this->log->queries[0]['sql']);
    }

    public static function patterns(): iterable
    {
        yield 'wildcard percent' => [['code LIKE' => '50%'], [1, 2]];
        yield 'wildcard underscore' => [['code LIKE' => 'a_b'], [3, 4]];
        yield 'escaped percent' => [['code LIKE' => '50\\%'], [1]];
        yield 'escaped underscore' => [['code LIKE' => 'a\\_b'], [3]];
        yield 'escaped backslash' => [['code LIKE' => 'C:\\\\dir'], [5]];
        yield 'escaped plain character' => [['code LIKE' => 'C:\\dir'], [6]];
        yield 'escape' => [['code LIKE' => Like::escape('a_b')], [3]];
        yield 'contains' => [['code LIKE' => Like::contains('\\')], [5, 7]];
        yield 'starts with' => [['code LIKE' => Like::startsWith('C:\\')], [5]];
        yield 'ends with an escaped backslash' => [['code LIKE' => Like::endsWith('\\')], [7]];
        yield 'not like' => [['code NOT LIKE' => Like::contains('\\')], [1, 2, 3, 4, 6]];
        yield 'group' => [
            Filter::any(['code LIKE' => Like::startsWith('50%')], ['code LIKE' => Like::endsWith('_b')]),
            [1, 3],
        ];
    }

    public function testTranslatedRelatedAndWriteCriteriaEscapeWithABackslash(): void
    {
        self::assertSame([1, 2], array_column($this->items->withLocale('uk', 'en')->rows(
            ['id'],
            ['title LIKE' => Like::contains('%')],
            ['id' => 'ASC'],
        ), 'id'));
        self::assertStringContainsString(") LIKE ? ESCAPE '\\'", $this->log->queries[0]['sql']);

        $read = $this->items->read();
        self::assertSame([3], array_column($read->rows(['id'], Filter::related('tags', [
            'label LIKE' => Like::endsWith('_50%'),
        ])), 'id'));
        self::assertSame([3, 4], array_column($read->rows(['id'], Filter::related('tags', [
            'label LIKE' => '%_50%',
        ]), ['id' => 'ASC']), 'id'));

        self::assertSame(1, $this->items->updateBy(['code LIKE' => Like::startsWith('C:\\')], ['code' => 'moved']));
        self::assertSame(1, $this->items->deleteBy(['code LIKE' => Like::contains('_')]));
        self::assertSame([1, 2, 4, 5, 6, 7], array_column($read->rows(['id'], orderBy: ['id' => 'ASC']), 'id'));
        self::assertSame('moved', $read->find(5)->code);
    }

    /** @param Closure(Repository): mixed $call */
    #[DataProvider('unpairedEscapes')]
    public function testPatternsEndingInAnUnpairedEscapeAreRejectedBeforeSql(Closure $call, string $field): void
    {
        try {
            $call($this->items);
            self::fail('Expected a LIKE pattern ending in an unpaired escape character to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                sprintf(
                    'The LIKE pattern of field "%s" ends in an unpaired escape character "\\"; '
                        . 'write "\\\\" for a literal backslash.',
                    $field,
                ),
                $exception->getMessage(),
            );
            self::assertSame([], $this->log->queries);
        }
    }

    public static function unpairedEscapes(): iterable
    {
        $pattern = new class implements Stringable {
            public function __toString(): string
            {
                return '%\\';
            }
        };

        yield 'like' => [
            static fn (Repository $items): mixed => $items->read()->rows(['id'], ['code LIKE' => 'end\\']),
            'code',
        ];
        yield 'not like after an escaped backslash' => [
            static fn (Repository $items): mixed => $items->read()->count(['code NOT LIKE' => '\\\\\\']),
            'code',
        ];
        yield 'stringable' => [
            static fn (Repository $items): mixed => $items->read()->exists(['code LIKE' => $pattern]),
            'code',
        ];
        yield 'translated' => [
            static fn (Repository $items): mixed => $items->withLocale('uk')->rows(['id'], ['title LIKE' => 'x\\']),
            'title',
        ];
        yield 'related' => [
            static fn (Repository $items): mixed => $items->read()->exists(
                Filter::related('tags', ['label LIKE' => '\\']),
            ),
            'label',
        ];
        yield 'write' => [
            static fn (Repository $items): mixed => $items->deleteBy(['code LIKE' => 'end\\']),
            'code',
        ];
    }
}
