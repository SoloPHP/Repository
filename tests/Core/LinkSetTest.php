<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Logging\Middleware;
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
use Stringable;

final class LinkSetTest extends TestCase
{
    private Connection $db;
    private Mapping $mapping;
    private Repository $tags;
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
        $this->tags = new Repository($this->db, new Mapping(
            'tags',
            ['id' => Types::INTEGER, 'deleted_at' => Types::DATETIME_IMMUTABLE],
            static fn (array $row): object => (object) $row,
            softDeleteColumn: 'deleted_at',
        ));
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
        $links = new LinkSet($this->db, $this->mapping, 'tags');
        $links->attach('1', [1, '1', 2]);
        $links->attach(1, [2, 3]);
        self::assertSame([1, 2, 3], $this->ids(1));

        $links->detach(1, [2, '2', 999]);
        $links->detach(1, [2]);
        self::assertSame([1, 3], $this->ids(1));
    }

    public function testSyncUsesPhysicalRowsIncludingSoftDeletedOwnersAndTargets(): void
    {
        $links = new LinkSet($this->db, $this->mapping, 'tags');
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
        $links = new LinkSet($this->db, $this->mapping, 'tags');
        $links->attach(1, []);
        $links->detach(1, []);

        self::assertSame([], $this->log->queries);
        self::assertFalse($this->db->isTransactionActive());
    }

    public function testWritesAreChunkedWithinTheParameterBudget(): void
    {
        $links = new LinkSet($this->db, $this->mapping, 'tags');
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

    public function testFailedLaterInsertChunkRestoresDeletedAndInsertedLinks(): void
    {
        $links = new LinkSet($this->db, $this->mapping, 'tags');
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
        $links = new LinkSet($this->db, $this->mapping, 'tags');
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

        (new LinkSet($this->db, $mapping, 'tags'))->sync('owner-a', ['1', 2]);
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
        $links = new LinkSet($this->db, $this->mapping, 'tags');
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

    public function testNonManyToManyRelationsAreRejected(): void
    {
        $mapping = new Mapping(
            'products',
            ['id' => Types::INTEGER],
            static fn (array $row): object => (object) $row,
            relations: ['tags' => new HasMany(
                fn (): Repository => $this->tags,
                'owner_id',
                static fn (object $entity, mixed $related): object => $entity,
            )],
        );

        $this->expectException(InvalidArgumentException::class);
        new LinkSet($this->db, $mapping, 'tags');
    }

    private function relation(string $table, string $ownerKey, string $targetKey): BelongsToMany
    {
        return new BelongsToMany(
            fn (): Repository => $this->tags,
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
        $target = new Repository($this->db, new Mapping(
            'case_targets',
            ['id' => Types::STRING, 'deleted_at' => Types::DATETIME_IMMUTABLE],
            static fn (array $row): object => (object) $row,
            generatedId: false,
            softDeleteColumn: 'deleted_at',
        ));
        $mapping = new Mapping(
            'case_owners',
            ['id' => Types::STRING],
            static fn (array $row): object => (object) $row,
            generatedId: false,
            relations: ['targets' => new BelongsToMany(
                static fn (): Repository => $target,
                'case_links',
                'owner_id',
                'target_id',
                static fn (object $entity, mixed $related): object => $entity,
            )],
        );
        $this->log->queries = [];

        return new LinkSet($this->db, $mapping, 'targets');
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
