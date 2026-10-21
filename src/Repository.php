<?php

declare(strict_types=1);

namespace Solo\Repository;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use LogicException;
use Solo\Repository\Internal\Database;
use Solo\Repository\Internal\Dialect;
use Solo\Repository\Internal\KeyType;
use Solo\Repository\Internal\LoadPlan;
use Solo\Repository\Internal\PredicateCompiler;
use Solo\Repository\Internal\RowMapper;
use Solo\Repository\Internal\SoftDelete;

/**
 * Explicit writes and independent read queries over one mapped table.
 *
 * @template-covariant T of object
 */
final readonly class Repository
{
    private Database $db;

    /** @var RowMapper<T> */
    private RowMapper $mapper;

    /**
     * @param Mapping<T> $mapping
     * @param int|null $parameterLimit Bound parameters per statement; defaults to the platform limit.
     */
    public function __construct(Connection $connection, private Mapping $mapping, ?int $parameterLimit = null)
    {
        $this->db = new Database($connection, $parameterLimit);
        $this->mapper = new RowMapper($mapping, $this->db);
    }

    /** @return ReadQuery<T> */
    public function read(): ReadQuery
    {
        return new ReadQuery($this->db, $this->mapping, Visibility::Active, null, LoadPlan::from([]));
    }

    /** @return ReadQuery<T> */
    public function withLocale(string $locale, ?string $fallback = null): ReadQuery
    {
        return $this->read()->withLocale($locale, $fallback);
    }

    /**
     * @param list<string|Load> $relations
     * @return ReadQuery<T>
     */
    public function with(array $relations): ReadQuery
    {
        return $this->read()->with($relations);
    }

    public function links(string $relation): LinkSet
    {
        return new LinkSet($this->db, $this->mapping, $relation);
    }

    public function translations(): TranslationStore
    {
        return new TranslationStore($this->db, $this->mapping);
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        return $this->executeInsert($this->validateInsert($data));
    }

    /** @param array<string, mixed> $data */
    public function insertGetId(array $data): int|string
    {
        $data = $this->validateInsert($data);
        $key = $this->mapping->primaryKey;
        if (array_key_exists($key, $data)) {
            /** @var int|string $id Normalized by validateInsert(). */
            $id = $data[$key];
            $this->executeInsert($data);

            return $id;
        }

        $dialect = $this->db->requireDialect('Generated IDs');
        [$sql, $parameters, $types] = $this->insertStatement(array_keys($data), [$data]);
        if ($dialect === Dialect::PostgreSQL) {
            $result = $this->db->connection->executeQuery(
                $sql . ' RETURNING ' . $this->db->quote($key),
                $parameters,
                $types,
            );
            try {
                $id = $result->fetchOne();
            } finally {
                $result->free();
            }
        } else {
            $this->db->connection->executeStatement($sql, $parameters, $types);
            $id = $this->db->connection->lastInsertId();
        }

        return $this->mapper->rowId($id);
    }

    /**
     * Inserts one rectangular batch atomically, splitting SQL at the parameter limit.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insertMany(array $rows, int $batchSize = 500): int
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Expected a positive batch size.');
        }
        $rows = $this->validateRows($rows);
        if ($rows === []) {
            return 0;
        }
        $columns = array_keys($rows[0]);
        // An empty row uses the platform's single-row default-values INSERT.
        $size = $columns === [] ? 1 : min($batchSize, $this->db->batchSize(count($columns)));

        return $this->db->connection->transactional(function () use ($rows, $columns, $size): int {
            $affected = 0;
            foreach (array_chunk($rows, $size) as $chunk) {
                [$sql, $parameters, $types] = $this->insertStatement($columns, $chunk);
                $affected += (int) $this->db->connection->executeStatement($sql, $parameters, $types);
            }

            return $affected;
        });
    }

    /** @param array<string, mixed> $patch */
    public function update(int|string $id, array $patch): int
    {
        return $this->updateBy([$this->mapping->primaryKey => $this->mapper->id($id)], $patch);
    }

    /**
     * @param array<string, mixed>|Filter $criteria
     * @param array<string, mixed> $patch
     */
    public function updateBy(array|Filter $criteria, array $patch): int
    {
        $this->validateColumns($patch);
        foreach ([$this->mapping->primaryKey, $this->mapping->softDeleteColumn] as $reserved) {
            if ($reserved !== null && array_key_exists($reserved, $patch)) {
                throw new InvalidArgumentException('Use dedicated operations instead of changing ID or visibility.');
            }
        }

        return $this->executeUpdate($criteria, $patch, Visibility::Active);
    }

    public function delete(int|string $id): int
    {
        return $this->deleteBy([$this->mapping->primaryKey => $this->mapper->id($id)]);
    }

    /** @param array<string, mixed>|Filter $criteria */
    public function deleteBy(array|Filter $criteria): int
    {
        $column = $this->mapping->softDeleteColumn;
        if ($column === null) {
            return $this->executeDelete($criteria, Visibility::Active);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $value = in_array(
            $this->mapping->columnType($column),
            [Types::DATETIME_MUTABLE, Types::DATETIMETZ_MUTABLE],
            true,
        ) ? DateTime::createFromImmutable($now) : $now;

        return $this->executeUpdate($criteria, [$column => $value], Visibility::Active);
    }

    public function restore(int|string $id): int
    {
        if ($this->mapping->softDeleteColumn === null) {
            throw new LogicException('Restore requires a soft-delete column.');
        }

        return $this->executeUpdate(
            [$this->mapping->primaryKey => $this->mapper->id($id)],
            [$this->mapping->softDeleteColumn => null],
            Visibility::Deleted,
        );
    }

    public function purge(int|string $id): int
    {
        return $this->purgeBy([$this->mapping->primaryKey => $this->mapper->id($id)]);
    }

    /** @param array<string, mixed>|Filter $criteria */
    public function purgeBy(array|Filter $criteria): int
    {
        return $this->executeDelete($criteria, Visibility::All);
    }

    /**
     * Locks the physical rows with these primary keys until the surrounding transaction ends.
     * Each statement locks in key order, and integer keys are sorted before splitting, so the
     * order holds across statements. SQLite has no row locks and only runs the SELECT.
     *
     * @param int|string|list<int|string> $ids
     */
    public function lockForUpdate(int|string|array $ids): void
    {
        $ids = array_values($this->mapper->ids(is_array($ids) ? $ids : [$ids]));
        if (!$this->db->connection->isTransactionActive()) {
            throw new LogicException('Row locks require an active transaction.');
        }
        if ($this->mapper->keyType() === KeyType::Integer) {
            sort($ids);
        }

        $key = $this->db->quote($this->mapping->primaryKey);
        $type = $this->mapping->columnType($this->mapping->primaryKey);
        foreach (array_chunk($ids, $this->db->keyBatchSize()) as $chunk) {
            $query = $this->db->connection->createQueryBuilder()
                ->select($key)
                ->from($this->db->quote($this->mapping->table))
                ->orderBy($key);
            $query->where($this->db->inList($query, $key, $type, $chunk));
            if ($this->db->dialect() !== Dialect::SQLite) {
                $query->forUpdate();
            }
            $result = $query->executeQuery();
            try {
                $result->fetchFirstColumn();
            } finally {
                $result->free();
            }
        }
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    private function validateInsert(array $data): array
    {
        $data = $this->validateColumns($data);
        $key = $this->mapping->primaryKey;
        if (array_key_exists($key, $data)) {
            if (!is_int($data[$key]) && !is_string($data[$key])) {
                throw new InvalidArgumentException('An explicit primary key must be an integer or string.');
            }
            $data[$key] = $this->mapper->id($data[$key]);
            if ($this->mapping->generatedId && $data[$key] === 0 && $this->db->dialect() === Dialect::MySQL) {
                throw new InvalidArgumentException('Omit the MySQL generated ID instead of supplying zero.');
            }
        } elseif (!$this->mapping->generatedId) {
            throw new InvalidArgumentException('An explicit primary key is required by this mapping.');
        }

        return $data;
    }

    /**
     * @param array<array-key, mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function validateRows(array $rows): array
    {
        if (!array_is_list($rows)) {
            throw new InvalidArgumentException('Expected a list of insert rows.');
        }
        $validated = [];
        $columns = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Every insert row must be an array.');
            }
            $row = $this->validateInsert($row);
            ksort($row);
            $keys = array_keys($row);
            if ($columns !== null && $keys !== $columns) {
                throw new InvalidArgumentException('Every insert row must contain the same columns.');
            }
            $columns = $keys;
            $validated[] = $row;
        }

        return $validated;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    private function validateColumns(array $data): array
    {
        $validated = [];
        foreach ($data as $column => $value) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Data must be keyed by mapped column names.');
            }
            $this->mapping->columnType($column);
            $validated[$column] = $value;
        }
        $this->db->assertParameters(count($validated));

        return $validated;
    }

    /** @param array<string, mixed> $data Validated insert data. */
    private function executeInsert(array $data): int
    {
        [$sql, $parameters, $types] = $this->insertStatement(array_keys($data), [$data]);

        return (int) $this->db->connection->executeStatement($sql, $parameters, $types);
    }

    /**
     * One multi-row INSERT with positional parameters; no columns means one default-values row.
     *
     * @param list<string> $columns
     * @param non-empty-list<array<string, mixed>> $rows Validated rows with exactly these columns.
     * @return array{string, list<mixed>, list<string>}
     */
    private function insertStatement(array $columns, array $rows): array
    {
        if ($columns === []) {
            return [
                $this->db->platform()->getEmptyIdentityInsertSQL(
                    $this->db->quote($this->mapping->table),
                    $this->db->quote($this->mapping->primaryKey),
                ),
                [],
                [],
            ];
        }

        $columnTypes = array_map($this->mapping->columnType(...), $columns);
        $parameters = [];
        $types = [];
        foreach ($rows as $row) {
            foreach ($columns as $index => $column) {
                $parameters[] = $row[$column];
                $types[] = $columnTypes[$index];
            }
        }

        return [$this->db->insertSql($this->mapping->table, $columns, count($rows)), $parameters, $types];
    }

    /**
     * @param array<string, mixed>|Filter $criteria
     * @param array<string, mixed> $patch
     */
    private function executeUpdate(array|Filter $criteria, array $patch, Visibility $visibility): int
    {
        $builder = $this->db->connection->createQueryBuilder()->update($this->db->quote($this->mapping->table));
        foreach ($patch as $column => $value) {
            $builder->set(
                $this->db->quote($column),
                $builder->createNamedParameter($value, $this->mapping->columnType($column)),
            );
        }
        $matches = $this->restrictWrite($builder, $criteria, $visibility);

        return $patch === [] || !$matches ? 0 : (int) $builder->executeStatement();
    }

    /** @param array<string, mixed>|Filter $criteria */
    private function executeDelete(array|Filter $criteria, Visibility $visibility): int
    {
        $builder = $this->db->connection->createQueryBuilder()->delete($this->db->quote($this->mapping->table));

        return $this->restrictWrite($builder, $criteria, $visibility) ? (int) $builder->executeStatement() : 0;
    }

    /** @param array<string, mixed>|Filter $criteria */
    private function restrictWrite(QueryBuilder $builder, array|Filter $criteria, Visibility $visibility): bool
    {
        $condition = (new PredicateCompiler($this->db, $this->mapping, $builder))->compile($criteria);
        if ($condition === true) {
            throw new InvalidArgumentException('A write requires a nonempty filter; use DBAL for all-row writes.');
        }
        if ($condition === false) {
            return false;
        }
        $builder->where($condition);
        $visible = SoftDelete::condition($this->mapping, $visibility, $this->db->quote(...));
        if ($visible !== null) {
            $builder->andWhere($visible);
        }

        return true;
    }
}
