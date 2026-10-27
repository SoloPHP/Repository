<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Closure;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Solo\Repository\LinkSet;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsToMany;
use Solo\Repository\Relations\HasMany;
use Solo\Repository\Repository;
use Solo\Repository\Tests\Fixtures\RecordsStatements;
use Stringable;

final class LinkSetTest extends TestCase
{
    use RecordsStatements;

    private Connection $db;
    private Mapping $mapping;
    private Mapping $tags;
    private AbstractLogger $log;

    protected function setUp(): void
    {
        $this->log = new class extends AbstractLogger {
            public array $queries = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    $this->queries[] = $context;
                }
            }
        };
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->log)]);
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->db->executeStatement('PRAGMA foreign_keys = ON');
        $this->db->executeStatement('CREATE TABLE products (id INTEGER PRIMARY KEY, deleted_at DATETIME)');
        $this->db->executeStatement('CREATE TABLE tags (id INTEGER PRIMARY KEY, deleted_at DATETIME)');
        $this->db->executeStatement('CREATE TABLE product_tag (
            product_id INTEGER NOT NULL REFERENCES products(id),
            tag_id INTEGER NOT NULL REFERENCES tags(id),
            PRIMARY KEY (product_id, tag_id)
        )');
        $this->db->executeStatement("INSERT INTO products VALUES (1, NULL), (2, '2026-01-01 00:00:00')");
        $this->db->executeStatement('INSERT INTO tags (id) VALUES ' . implode(', ', array_map(
            static fn (int $id): string => "($id)",
            range(1, 1002),
        )));
        $this->db->executeStatement("UPDATE tags SET deleted_at = '2026-01-01 00:00:00' WHERE id = 2");
        $this->tags = new Mapping(
            'tags',
            ['id' => Types::INTEGER, 'deleted_at' => Types::DATETIME_IMMUTABLE],
            static fn (array $row): object => (object) $row,
            softDeleteColumn: 'deleted_at',
        );
        $this->mapping = new Mapping(
            'products',
            ['id' => Types::INTEGER, 'deleted_at' => Types::DATETIME_IMMUTABLE],
            static fn (array $row): object => (object) $row,
            softDeleteColumn: 'deleted_at',
            relations: ['tags' => $this->relation('product_tag', 'product_id', 'tag_id')],
        );
        $this->log->queries = [];
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testAttachAndDetachAreIdempotentAndNormalizeIds(): void
    {
        $links = $this->links($this->mapping);
        $links->attach('1', [1, '1', 2]);
        $links->attach(1, [2, 3]);
        self::assertSame([1, 2, 3], $this->ids(1));

        $links->detach(1, [2, '2', 999]);
        $links->detach(1, [2]);
        self::assertSame([1, 3], $this->ids(1));
    }

    public function testSyncUsesPhysicalRowsIncludingSoftDeletedOwnersAndTargets(): void
    {
        $links = $this->links($this->mapping);
        $links->attach(1, [1, 2]);
        $links->attach(2, [1, 2]);
        $links->sync(1, [2, 3]);
        self::assertSame([2, 3], $this->ids(1));
        self::assertSame([1, 2], $this->ids(2));
        $links->sync(1, []);
        self::assertSame([], $this->ids(1));
        self::assertSame([1, 2], $this->ids(2));
    }

    public function testEmptyAttachAndDetachDoNotIssueSql(): void
    {
        $links = $this->links($this->mapping);
        $links->attach(1, []);
        $links->detach(1, []);

        self::assertSame([], $this->log->queries);
        self::assertFalse($this->db->isTransactionActive());
    }

    public function testWritesAreChunkedWithinTheParameterBudget(): void
    {
        $links = $this->links($this->mapping);
        $links->attach(1, range(1, 1001));
        $insertQueries = array_filter(
            $this->log->queries,
            static fn (array $q): bool => str_starts_with($q['sql'], 'INSERT'),
        );
        self::assertCount(3, $insertQueries);
        foreach ($insertQueries as $query) {
            self::assertLessThanOrEqual(900, count($query['params']));
        }

        $this->log->queries = [];
        $links->detach(1, range(1, 1001));
        self::assertCount(2, $this->log->queries);
        foreach ($this->log->queries as $query) {
            self::assertLessThanOrEqual(900, count($query['params']));
        }
        self::assertSame([], $this->ids(1));
    }

    public function testPivotWritesRequireRoomForOneLinkRow(): void
    {
        $links = (new Repository($this->db, $this->mapping, parameterLimit: 1))->links('tags');
        foreach ([fn () => $links->attach(1, [3]), fn () => $links->detach(1, [3])] as $write) {
            try {
                $write();
                self::fail('Expected the parameter limit to reject a pivot row.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('parameter limit of 1', $exception->getMessage());
            }
        }

        self::assertSame([], $this->ids(1));
        self::assertFalse($this->db->isTransactionActive());
    }

    public function testFailedLaterInsertChunkRestoresDeletedAndInsertedLinks(): void
    {
        $links = $this->links($this->mapping);
        $links->attach(1, [1002]);

        try {
            $links->sync(1, [...range(1, 901), 2000]);
            self::fail('Expected an FK failure in a later insert chunk.');
        } catch (ForeignKeyConstraintViolationException) {
            self::assertSame([1002], $this->ids(1));
            self::assertFalse($this->db->isTransactionActive());
        }
    }

    public function testNestedFailureRollsBackOnlyTheLinkSetOperation(): void
    {
        $links = $this->links($this->mapping);
        $this->db->transactional(function () use ($links): void {
            $links->attach(2, [1]);
            try {
                $links->attach(1, [...range(1, 450), 2000]);
                self::fail('Expected a foreign key failure.');
            } catch (ForeignKeyConstraintViolationException) {
                self::assertTrue($this->db->isTransactionActive());
                self::assertSame([], $this->ids(1));
                self::assertSame([1], $this->ids(2));
            }
        });

        self::assertSame([1], $this->ids(2));
    }

    public function testOwnerAndTargetKeyTypesCanDiffer(): void
    {
        $this->db->executeStatement('CREATE TABLE string_owners (id TEXT PRIMARY KEY)');
        $this->db->executeStatement('CREATE TABLE string_links (
            owner_id TEXT NOT NULL REFERENCES string_owners(id),
            tag_id INTEGER NOT NULL REFERENCES tags(id),
            PRIMARY KEY (owner_id, tag_id)
        )');
        $this->db->executeStatement("INSERT INTO string_owners VALUES ('owner-a')");
        $mapping = new Mapping(
            'string_owners',
            ['id' => Types::STRING],
            static fn (array $row): object => (object) $row,
            generatedId: false,
            relations: ['tags' => $this->relation('string_links', 'owner_id', 'tag_id')],
        );

        $this->links($mapping)->sync('owner-a', ['1', 2]);
        self::assertSame([1, 2], array_map('intval', $this->db->fetchFirstColumn('SELECT tag_id FROM string_links')));
    }

    public function testCaseInsensitiveKeysUseDatabaseEqualityWithoutRewritingExistingLinks(): void
    {
        $links = $this->caseInsensitiveLinks();
        $links->attach('Owner-A', ['tag-a', 'TAG-A']);
        $writes = array_filter(
            $this->log->queries,
            static fn (array $query): bool => preg_match('/^(INSERT|DELETE)\b/', $query['sql']) === 1,
        );
        self::assertSame([], $writes);
        self::assertSame([['owner_id' => 'OWNER-A', 'target_id' => 'TAG-A']], $this->caseRows());

        $links->sync('OWNER-A', ['Tag-A', 'TAG-B']);
        self::assertSame([
            ['owner_id' => 'OWNER-A', 'target_id' => 'TAG-A'],
            ['owner_id' => 'owner-a', 'target_id' => 'tag-b'],
        ], $this->caseRows());
        $links->sync('Owner-a', ['TAG-B']);
        self::assertSame([['owner_id' => 'owner-a', 'target_id' => 'tag-b']], $this->caseRows());
        $links->detach('OWNER-A', ['tAg-B']);
        self::assertSame([], $this->caseRows());
    }

    public function testCaseInsensitiveSyncEmptyClearsEveryPhysicalOwnerSpelling(): void
    {
        $links = $this->caseInsensitiveLinks();
        $links->attach('owner-A', ['TAG-B']);
        $links->sync('Owner-A', []);

        self::assertSame([], $this->caseRows());
        self::assertSame([['owner_id' => 'other', 'target_id' => 'tag-c']], $this->db->fetchAllAssociative(
            'SELECT owner_id, target_id FROM case_links',
        ));
    }

    public function testCanonicalizationKeepsMissingTargetsForForeignKeyErrors(): void
    {
        $links = $this->caseInsensitiveLinks();

        try {
            $links->sync('Owner-A', ['TAG-B', 'missing']);
            self::fail('Missing target must still reach the FK constraint.');
        } catch (ForeignKeyConstraintViolationException) {
            self::assertSame([['owner_id' => 'OWNER-A', 'target_id' => 'TAG-A']], $this->caseRows());
        }
    }

    public function testBroaderPivotCollationCannotExpandTheOwnerScope(): void
    {
        $links = $this->caseInsensitiveLinks(caseSensitiveOwner: true);
        $this->db->executeStatement("INSERT INTO case_owners VALUES ('A'), ('a')");
        $this->db->executeStatement("INSERT INTO case_links VALUES ('A', 'tag-a'), ('a', 'tag-b')");

        $links->detach('A', ['TAG-B']);
        self::assertSame([['owner_id' => 'a', 'target_id' => 'tag-b']], $this->caseRows('a'));
        $links->sync('A', []);

        self::assertSame([], $this->caseRows('A'));
        self::assertSame([['owner_id' => 'a', 'target_id' => 'tag-b']], $this->caseRows('a'));
    }

    public function testBroaderPivotCollationCannotDeleteAnotherCanonicalTarget(): void
    {
        $links = $this->caseInsensitiveLinks(caseSensitiveTarget: true);
        $this->db->executeStatement("INSERT INTO case_targets (id) VALUES ('A'), ('a')");
        $this->db->executeStatement("INSERT INTO case_links VALUES ('OWNER-A', 'A'), ('owner-a', 'a')");

        $links->sync('Owner-A', ['A']);
        self::assertSame([['owner_id' => 'OWNER-A', 'target_id' => 'A']], $this->caseRows());
        $links->detach('owner-a', ['a']);
        self::assertSame([['owner_id' => 'OWNER-A', 'target_id' => 'A']], $this->caseRows());
        $links->detach('owner-a', ['A']);
        self::assertSame([], $this->caseRows());
    }

    public function testCanonicalizationChunksLargeStringIdListsBelowCompoundSelectLimit(): void
    {
        $links = $this->caseInsensitiveLinks();
        $this->db->executeStatement('INSERT INTO case_targets (id) VALUES ' . implode(', ', array_map(
            static fn (int $id): string => "('tag-$id')",
            range(1, 901),
        )));
        $this->log->queries = [];

        $links->attach('Owner-A', array_map(static fn (int $id): string => "TAG-$id", range(1, 901)));

        $canonicalization = array_filter(
            $this->log->queries,
            static fn (array $query): bool => str_contains($query['sql'], ' UNION ALL SELECT ?'),
        );
        self::assertCount(3, $canonicalization);
        foreach ($canonicalization as $query) {
            self::assertLessThanOrEqual(450, count($query['params']));
        }
        self::assertCount(902, $this->caseRows());
    }

    #[DataProvider('invalidIds')]
    public function testInvalidRelatedIdsAreRejectedBeforeSql(array $ids): void
    {
        $links = $this->links($this->mapping);
        $this->expectException(InvalidArgumentException::class);
        try {
            $links->sync(1, $ids);
        } finally {
            self::assertSame([], $this->log->queries);
        }
    }

    public static function invalidIds(): iterable
    {
        yield [[null]];
        yield [[true]];
        yield [[1.5]];
        yield [['2garbage']];
        yield [[[]]];
        yield [['id' => 1]];
    }

    /**
     * A pair another writer stores between the operation's read and its INSERT conflicts over the
     * pivot's UNIQUE pair; the INSERT skips it instead of failing. Platforms without upserts keep
     * the plain INSERT.
     */
    #[DataProvider('pairInserts')]
    public function testInsertsSkipPairsStoredSinceTheRead(AbstractPlatform $platform, string $insert): void
    {
        [$connection, $statements] = $this->recordingConnection($platform);
        $connection->method('transactional')->willReturnCallback(
            static fn (Closure $operation): mixed => $operation($connection),
        );

        (new Repository($connection, $this->mapping))->links('tags')->attach(1, [3, 4]);

        $inserts = array_values(array_filter(
            $statements->getArrayCopy(),
            static fn (array $statement): bool => str_starts_with($statement[0], 'INSERT'),
        ));
        self::assertSame([[$insert, [1, 3, 1, 4], array_fill(0, 4, Types::INTEGER)]], $inserts);
    }

    /** @return iterable<string, array{AbstractPlatform, string}> */
    public static function pairInserts(): iterable
    {
        $mysql = 'INSERT INTO `product_tag` (`product_id`, `tag_id`) VALUES (?, ?), (?, ?)'
            . ' ON DUPLICATE KEY UPDATE `product_id` = `product_id`';
        $conflict = 'INSERT INTO "product_tag" AS "br_target" ("product_id", "tag_id") VALUES (?, ?), (?, ?)'
            . ' ON CONFLICT ("product_id", "tag_id") DO NOTHING';
        yield 'MySQL' => [new MySQLPlatform(), $mysql];
        yield 'MariaDB' => [new MariaDBPlatform(), $mysql];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), $conflict];
        yield 'SQLite' => [new SQLitePlatform(), $conflict];
        yield 'other platforms' => [
            new SQLServerPlatform(),
            'INSERT INTO [product_tag] ([product_id], [tag_id]) VALUES (?, ?), (?, ?)',
        ];
    }

    public function testNonManyToManyRelationsAreRejected(): void
    {
        $mapping = new Mapping(
            'products',
            ['id' => Types::INTEGER],
            static fn (array $row): object => (object) $row,
            relations: ['tags' => new HasMany(
                fn (): Mapping => $this->tags,
                'owner_id',
                static fn (object $entity, mixed $related): object => $entity,
            )],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->links($mapping);
    }

    private function relation(string $table, string $ownerKey, string $targetKey): BelongsToMany
    {
        return new BelongsToMany(
            fn (): Mapping => $this->tags,
            $table,
            $ownerKey,
            $targetKey,
            static fn (object $entity, mixed $related): object => $entity,
        );
    }

    private function caseInsensitiveLinks(bool $caseSensitiveOwner = false, bool $caseSensitiveTarget = false): LinkSet
    {
        $ownerCollation = $caseSensitiveOwner ? 'BINARY' : 'NOCASE';
        $targetCollation = $caseSensitiveTarget ? 'BINARY' : 'NOCASE';
        $pivotOwnerCollation = $caseSensitiveOwner ? 'NOCASE' : 'BINARY';
        $pivotTargetCollation = $caseSensitiveTarget ? 'NOCASE' : 'BINARY';
        $this->db->executeStatement("CREATE TABLE case_owners (id TEXT COLLATE $ownerCollation PRIMARY KEY)");
        $this->db->executeStatement(
            "CREATE TABLE case_targets (id TEXT COLLATE $targetCollation PRIMARY KEY, deleted_at DATETIME)",
        );
        $this->db->executeStatement("CREATE TABLE case_links (
            owner_id TEXT COLLATE $pivotOwnerCollation NOT NULL REFERENCES case_owners(id),
            target_id TEXT COLLATE $pivotTargetCollation NOT NULL REFERENCES case_targets(id),
            UNIQUE(owner_id, target_id)
        )");
        $this->db->executeStatement("INSERT INTO case_owners VALUES ('owner-a'), ('other')");
        $this->db->executeStatement("INSERT INTO case_targets VALUES
            ('tag-a', NULL), ('tag-b', '2026-01-01 00:00:00'), ('tag-c', NULL)");
        $this->db->insert('case_links', [
            'owner_id' => $caseSensitiveOwner ? 'owner-a' : 'OWNER-A',
            'target_id' => $caseSensitiveTarget ? 'tag-a' : 'TAG-A',
        ]);
        $this->db->executeStatement("INSERT INTO case_links VALUES ('other', 'tag-c')");
        $target = new Mapping(
            'case_targets',
            ['id' => Types::STRING, 'deleted_at' => Types::DATETIME_IMMUTABLE],
            static fn (array $row): object => (object) $row,
            generatedId: false,
            softDeleteColumn: 'deleted_at',
        );
        $mapping = new Mapping(
            'case_owners',
            ['id' => Types::STRING],
            static fn (array $row): object => (object) $row,
            generatedId: false,
            relations: ['targets' => new BelongsToMany(
                static fn (): Mapping => $target,
                'case_links',
                'owner_id',
                'target_id',
                static fn (object $entity, mixed $related): object => $entity,
            )],
        );
        $this->log->queries = [];

        return $this->links($mapping, 'targets');
    }

    private function links(Mapping $mapping, string $relation = 'tags'): LinkSet
    {
        return (new Repository($this->db, $mapping, parameterLimit: 900))->links($relation);
    }

    private function caseRows(string $owner = 'owner-a'): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT l.owner_id, l.target_id FROM case_links l JOIN case_owners o ON o.id = l.owner_id'
                . ' WHERE o.id = ? ORDER BY l.target_id',
            [$owner],
        );
    }

    private function ids(int $owner): array
    {
        return array_map('intval', $this->db->fetchFirstColumn(
            'SELECT tag_id FROM product_tag WHERE product_id = ? ORDER BY tag_id',
            [$owner],
        ));
    }
}
