<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use Error;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsToMany;
use Solo\Repository\Repository;
use Solo\Repository\TransactionManager;
use Solo\Repository\Translations;
use stdClass;

final class TransactionManagerTest extends TestCase
{
    private Connection $db;
    private TransactionManager $transactions;
    private Repository $products;
    private Repository $tags;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->transactions = new TransactionManager($this->db);
        $this->db->executeStatement('PRAGMA foreign_keys = ON');
        $this->db->executeStatement('CREATE TABLE products (id INTEGER PRIMARY KEY)');
        $this->db->executeStatement('CREATE TABLE tags (id INTEGER PRIMARY KEY)');
        $this->db->executeStatement('CREATE TABLE product_translations (
            product_id INTEGER NOT NULL REFERENCES products(id), locale TEXT NOT NULL, name TEXT,
            PRIMARY KEY (product_id, locale)
        )');
        $this->db->executeStatement('CREATE TABLE product_tag (
            product_id INTEGER NOT NULL REFERENCES products(id), tag_id INTEGER NOT NULL REFERENCES tags(id),
            PRIMARY KEY (product_id, tag_id)
        )');

        $tags = new Mapping('tags', ['id' => Types::INTEGER]);
        $this->tags = new Repository($this->db, $tags);
        $this->products = new Repository($this->db, new Mapping(
            'products',
            ['id' => Types::INTEGER],
            translations: new Translations('product_translations', 'product_id', ['name' => Types::STRING]),
            relations: ['tags' => new BelongsToMany(static fn () => $tags, 'product_tag', 'product_id', 'tag_id')],
        ));
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testConstructionIsLazyAndCallbackReceivesNoConnection(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $transactions = new TransactionManager($db);
            self::assertFalse($db->isConnected());
            self::assertSame(0, $transactions->run(static fn (): int => func_num_args()));
            self::assertTrue($db->isConnected());
            self::assertFalse($db->isTransactionActive());
            self::assertNull($transactions->run(static function (): void {
            }));
        } finally {
            $db->close();
        }
    }

    public function testCommitsRepositoriesTranslationsAndLinksAndReturnsTheResult(): void
    {
        $result = new stdClass();
        self::assertSame($result, $this->transactions->run(function () use ($result): stdClass {
            $this->writeProduct();

            return $result;
        }));

        self::assertSame(1, $this->products->read()->count());
        self::assertSame(2, $this->tags->read()->count());
        self::assertSame('Стіл', $this->db->fetchOne('SELECT name FROM product_translations'));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_tag'));
        self::assertFalse($this->db->isTransactionActive());
    }

    public function testThrowableRollsBackAllParticipantsAndIsNotRetriedOrReplaced(): void
    {
        $failure = new Error('Abort the operation');
        $attempts = 0;
        try {
            $this->transactions->run(function () use ($failure, &$attempts): void {
                $attempts++;
                $this->writeProduct();
                throw $failure;
            });
            self::fail('The operation must fail.');
        } catch (Error $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(1, $attempts);
        foreach (['products', 'tags', 'product_translations', 'product_tag'] as $table) {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . $table));
        }
        self::assertFalse($this->db->isTransactionActive());
    }

    public function testSuccessfulRunStillRollsBackWithAnOuterDbalTransaction(): void
    {
        $failure = new RuntimeException('Abort the outer transaction');
        try {
            $this->db->transactional(function () use ($failure): void {
                $this->transactions->run(fn () => $this->writeProduct());
                self::assertTrue($this->db->isTransactionActive());
                self::assertSame(1, $this->products->read()->count());
                throw $failure;
            });
            self::fail('The outer transaction must fail.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(0, $this->products->read()->count());
        self::assertSame(0, $this->tags->read()->count());
        self::assertFalse($this->db->isTransactionActive());
    }

    public function testCaughtInnerFailureRollsBackOnlyItsSavepoint(): void
    {
        // Another manager on the same connection participates in the same transaction.
        $inner = new TransactionManager($this->db);
        $failure = new RuntimeException('Abort the inner operation');
        $this->transactions->run(function () use ($inner, $failure): void {
            $this->products->insert(['id' => 1]);
            try {
                $inner->run(function () use ($failure): void {
                    $this->products->insert(['id' => 2]);
                    throw $failure;
                });
                self::fail('The inner operation must fail.');
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            self::assertTrue($this->db->isTransactionActive());
            $this->products->insert(['id' => 3]);
        });

        self::assertSame([['id' => 1], ['id' => 3]], $this->products->read()->rows(['id'], orderBy: ['id' => 'ASC']));
        self::assertFalse($this->db->isTransactionActive());
    }

    public function testASeparateConnectionDoesNotParticipateInRollback(): void
    {
        $other = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $other->executeStatement('CREATE TABLE products (id INTEGER PRIMARY KEY)');
            $otherProducts = new Repository($other, new Mapping('products', ['id' => Types::INTEGER]));
            $failure = new RuntimeException('Abort one connection');
            try {
                $this->transactions->run(function () use ($otherProducts, $failure): void {
                    $this->products->insert(['id' => 1]);
                    $otherProducts->insert(['id' => 2]);
                    throw $failure;
                });
                self::fail('The operation must fail.');
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }

            self::assertSame(0, $this->products->read()->count());
            self::assertSame([['id' => 2]], $otherProducts->read()->rows(['id']));
        } finally {
            $other->close();
        }
    }

    private function writeProduct(): void
    {
        $this->products->insert(['id' => 1]);
        $this->tags->insertMany([['id' => 2], ['id' => 5]], batchSize: 1);
        $this->products->translations()->save(1, 'uk', ['name' => 'Стіл']);
        $this->products->links('tags')->sync(1, [2, 5]);
    }
}
