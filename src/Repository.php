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
use Solo\Repository\Internal\FieldValue;
use Solo\Repository\Internal\KeyType;
use Solo\Repository\Internal\LoadPlan;
use Solo\Repository\Internal\PredicateCompiler;
use Solo\Repository\Internal\RowMapper;
use Solo\Repository\Internal\SoftDelete;

/**
 * Explicit writes and independent read queries over one mapped table.
 *
 * An application may extend it with a readonly class that fixes the mapping in
 * its own constructor, giving each table a typed service to inject, and adds
 * methods of its own. The public methods are final: a subclass adds to the
 * repository and cannot change what it does.
 *
 * @template-covariant T of object
 */
readonly class Repository
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
    final public function read(): ReadQuery
    {
        return new ReadQuery($this->db, $this->mapping, Visibility::Active, null, LoadPlan::from([]));
    }

    /** @return ReadQuery<T> */
    final public function withLocale(string $locale, ?string $fallback = null): ReadQuery
    {
        return $this->read()->withLocale($locale, $fallback);
    }

    /**
     * @param list<string|Load> $relations
     * @return ReadQuery<T>
     */
    final public function with(array $relations): ReadQuery
    {
        return $this->read()->with($relations);
    }

    final public function links(string $relation): LinkSet
    {
        return new LinkSet($this->db, $this->mapping, $relation);
    }

    final public function translations(): TranslationStore
    {
        return new TranslationStore($this->db, $this->mapping);
    }

    /** @param array<string, mixed> $data */
    final public function insert(array $data): int
    {
        return $this->executeInsert([$this->validateInsert($data)]);
    }

    /** @param array<string, mixed> $data */
    final public function insertGetId(array $data): int|string
    {
        $key = $this->mapping->requirePrimaryKey('insertGetId()');
        $data = $this->validateInsert($data);
        if (array_key_exists($key, $data)) {
            /** @var int|string $id Normalized by validateInsert(). */
            $id = $data[$key];
            $this->executeInsert([$data]);

            return $id;
        }

        $dialect = $this->db->requireDialect('Generated IDs');
        [$sql, $parameters, $types] = $this->insertStatement([$data]);
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
     * Inserts one rectangular batch atomically, splitting SQL at the parameter limit. A batch
     * that fits one statement runs as that statement, which is atomic on its own; a split batch
     * runs its statements in one transaction.
     *
     * @param list<array<string, mixed>> $rows
     */
    final public function insertMany(array $rows, int $batchSize = 500): int
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
        if (count($rows) <= $size) {
            return $this->executeInsert($rows);
        }

        return $this->db->connection->transactional(function () use ($rows, $size): int {
            $affected = 0;
            foreach (array_chunk($rows, $size) as $chunk) {
                $affected += $this->executeInsert($chunk);
            }

            return $affected;
        });
    }

    /**
     * Inserts the row of a unique key, or updates the row that already holds the key, in one
     * statement. $data is written either way and $onInsert only into a new row, such as its
     * explicit ID or creation time; the key of an existing row stays as it is, and with empty
     * $data so does the rest of the row. The row holding the key is updated even when it is
     * soft-deleted, and stays deleted.
     *
     * The key names the columns of one unique index or of the primary key: PostgreSQL and SQLite
     * reject a key without one, while MySQL updates the row on a conflict over any unique index
     * and inserts another row when no unique index covers the key.
     *
     * @param array<string, mixed> $key
     * @param array<string, mixed> $data
     * @param array<string, mixed> $onInsert
     */
    final public function upsert(array $key, array $data, array $onInsert = []): void
    {
        if ($key === []) {
            throw new InvalidArgumentException('An upsert requires the columns of a unique key.');
        }
        if (in_array(null, $key, true)) {
            throw new InvalidArgumentException('Upsert key values cannot be NULL.');
        }
        $row = $key + $data + $onInsert;
        if (count($row) !== count($key) + count($data) + count($onInsert)) {
            throw new InvalidArgumentException(
                'An upsert names each column once: in the key, the data or the insert-only values.',
            );
        }
        // A new row may get an explicit ID, but no upsert changes one, and none changes visibility.
        $this->assertUnreserved($data, $this->mapping->primaryKey);
        $this->assertUnreserved($row, $this->mapping->softDeleteColumn);
        $row = $this->validateInsert($row);

        $updates = [];
        foreach ($data as $column => $value) {
            $updates[$column] = [$value, $this->mapping->columnType($column)];
        }
        /** @var non-empty-list<string> $keyColumns */
        $keyColumns = array_keys($key);
        [$alias, $conflict, $updateParameters, $updateTypes] = $this->db->upsertClause(
            'Upserts',
            $keyColumns,
            $updates,
        );
        [$sql, $parameters, $types] = $this->insertStatement([$row], $alias);
        $this->db->assertParameters(count($parameters) + count($updateParameters));

        $this->db->connection->executeStatement(
            $sql . $conflict,
            [...$parameters, ...$updateParameters],
            [...$types, ...$updateTypes],
        );
    }

    /** @param array<string, mixed> $patch */
    final public function update(int|string $id, array $patch): int
    {
        return $this->updateBy([$this->mapping->requirePrimaryKey('update()') => $this->mapper->id($id)], $patch);
    }

    /**
     * @param array<string, mixed>|Filter $criteria
     * @param array<string, mixed> $patch
     */
    final public function updateBy(array|Filter $criteria, array $patch): int
    {
        $this->validateColumns($patch);
        $this->assertUnreserved($patch, $this->mapping->primaryKey, $this->mapping->softDeleteColumn);

        return $this->executeUpdate($criteria, $patch, Visibility::Active);
    }

    final public function delete(int|string $id): int
    {
        return $this->deleteBy([$this->mapping->requirePrimaryKey('delete()') => $this->mapper->id($id)]);
    }

    /** @param array<string, mixed>|Filter $criteria */
    final public function deleteBy(array|Filter $criteria): int
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

    final public function restore(int|string $id): int
    {
        if ($this->mapping->softDeleteColumn === null) {
            throw new LogicException('Restore requires a soft-delete column.');
        }

        return $this->executeUpdate(
            [$this->mapping->requirePrimaryKey('restore()') => $this->mapper->id($id)],
            [$this->mapping->softDeleteColumn => null],
            Visibility::Deleted,
        );
    }

    final public function purge(int|string $id): int
    {
        return $this->purgeBy([$this->mapping->requirePrimaryKey('purge()') => $this->mapper->id($id)]);
    }

    /** @param array<string, mixed>|Filter $criteria */
    final public function purgeBy(array|Filter $criteria): int
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
    final public function lockForUpdate(int|string|array $ids): void
    {
        $column = $this->mapping->requirePrimaryKey('lockForUpdate()');
        $ids = array_values($this->mapper->ids(is_array($ids) ? $ids : [$ids]));
        $this->db->assertTransaction();
        if ($this->mapper->keyType() === KeyType::Integer) {
            sort($ids);
        }

        $key = $this->db->quote($column);
        $type = $this->mapping->columnType($column);
        foreach (array_chunk($ids, $this->db->keyBatchSize()) as $chunk) {
            $query = $this->db->connection->createQueryBuilder()
                ->select($key)
                ->from($this->db->quote($this->mapping->table))
                ->orderBy($key);
            $query->where($this->db->inList($query, $key, $type, $chunk));
            $result = $this->db->executeLocked($query);
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
        if ($key === null) {
            if ($data === []) {
                throw new InvalidArgumentException('Rows of a mapping without a primary key need at least one column.');
            }

            return $data;
        }
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
            $columns ??= array_fill_keys(array_keys($row), true);
            if (count($row) !== count($columns) || array_diff_key($row, $columns) !== []) {
                throw new InvalidArgumentException('Every insert row must contain the same columns.');
            }
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
        foreach ($data as $column => $value) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Data must be keyed by mapped column names.');
            }
            $type = $this->mapping->columnType($column);
            if ($value !== null) {
                FieldValue::assert($column, $type, $value);
            }
        }
        $this->db->assertParameters(count($data));

        /** @var array<string, mixed> $data Every key was checked above; the rows are not copied. */
        return $data;
    }

    /** @param non-empty-list<array<string, mixed>> $rows Validated rows with the same columns. */
    private function executeInsert(array $rows): int
    {
        [$sql, $parameters, $types] = $this->insertStatement($rows);

        return (int) $this->db->connection->executeStatement($sql, $parameters, $types);
    }

    /**
     * One multi-row INSERT with positional parameters; no columns means one default-values row.
     *
     * @param non-empty-list<array<string, mixed>> $rows Validated rows with the same columns.
     * @param string|null $alias The name of the target table within the statement, if not its own.
     * @return array{string, list<mixed>, list<string>}
     */
    private function insertStatement(array $rows, ?string $alias = null): array
    {
        $columns = array_keys($rows[0]);
        if ($columns === []) {
            return [
                $this->db->platform()->getEmptyIdentityInsertSQL(
                    $this->db->quote($this->mapping->table),
                    $this->db->quote($this->mapping->requirePrimaryKey('An insert of default values')),
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

        return [$this->db->insertSql($this->mapping->table, $columns, count($rows), $alias), $parameters, $types];
    }

    /**
     * @param array<array-key, mixed> $columns
     * @param string|null ...$reserved Columns that only dedicated operations write.
     */
    private function assertUnreserved(array $columns, ?string ...$reserved): void
    {
        foreach ($reserved as $column) {
            if ($column !== null && array_key_exists($column, $columns)) {
                throw new InvalidArgumentException('Use dedicated operations instead of changing ID or visibility.');
            }
        }
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
