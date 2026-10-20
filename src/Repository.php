<?php

declare(strict_types=1);

namespace Solo\Repository;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use LogicException;
use Solo\Repository\Internal\PredicateCompiler;
use Solo\Repository\Internal\RowMapper;
use UnexpectedValueException;

/**
 * Explicit writes and independent read queries over one mapped table.
 *
 * @template T of object
 */
final readonly class Repository
{
    /** @var RowMapper<T> */
    private RowMapper $mapper;

    /** @param Mapping<T> $mapping */
    public function __construct(private Connection $connection, private Mapping $mapping)
    {
        $this->mapper = new RowMapper($mapping, $connection->getDatabasePlatform());
    }

    /** @return ReadQuery<T> */
    public function read(): ReadQuery
    {
        return new ReadQuery($this->connection, $this->mapping);
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
        return new LinkSet($this->connection, $this->mapping, $relation);
    }

    /** @internal */
    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * @internal
     * @return Mapping<T>
     */
    public function mapping(): Mapping
    {
        return $this->mapping;
    }

    public function translations(): TranslationStore
    {
        return new TranslationStore($this->connection, $this->mapping);
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        [$sql, $parameters, $types] = $this->insertStatement($this->validateInsert($data));

        return (int) $this->connection->executeStatement($sql, $parameters, $types);
    }

    /** @param array<string, mixed> $data */
    public function insertGetId(array $data): int|string
    {
        $data = $this->validateInsert($data);
        if (array_key_exists($this->mapping->primaryKey, $data)) {
            $this->insert($data);

            return $this->mapper->id($data[$this->mapping->primaryKey]);
        }

        $platform = $this->connection->getDatabasePlatform();
        if (
            !$platform instanceof PostgreSQLPlatform
            && !$platform instanceof AbstractMySQLPlatform
            && !$platform instanceof SQLitePlatform
        ) {
            throw new LogicException('Generated IDs are supported only on PostgreSQL, MySQL and SQLite.');
        }

        [$sql, $parameters, $types] = $this->insertStatement($data);
        if ($platform instanceof PostgreSQLPlatform) {
            $result = $this->connection->executeQuery(
                $sql . ' RETURNING ' . $this->quote($this->mapping->primaryKey),
                $parameters,
                $types,
            );
            try {
                $id = $result->fetchOne();
            } finally {
                $result->free();
            }
        } else {
            $this->connection->executeStatement($sql, $parameters, $types);
            $id = $this->connection->lastInsertId();
        }

        if (!is_int($id) && !is_string($id)) {
            throw new UnexpectedValueException('The database did not return an integer or string ID.');
        }

        return $this->mapper->id($id);
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
        $width = count($columns);
        $size = $width === 0 ? 1 : min($batchSize, intdiv(PredicateCompiler::MAX_PARAMETERS, $width));

        return $this->connection->transactional(function () use ($rows, $columns, $width, $size): int {
            $affected = 0;
            for ($offset = 0, $total = count($rows); $offset < $total; $offset += $size) {
                if ($width === 0) {
                    $affected += $this->insert([]);
                    continue;
                }

                $chunk = array_slice($rows, $offset, $size);
                $parameters = [];
                $types = [];
                foreach ($chunk as $row) {
                    foreach ($columns as $column) {
                        $parameters[] = $row[$column];
                        $types[] = $this->mapping->columnType($column);
                    }
                }
                $tuple = '(' . implode(', ', array_fill(0, $width, '?')) . ')';
                $sql = 'INSERT INTO ' . $this->quote($this->mapping->table)
                    . ' (' . implode(', ', array_map($this->quote(...), $columns)) . ') VALUES '
                    . implode(', ', array_fill(0, count($chunk), $tuple));
                $affected += (int) $this->connection->executeStatement($sql, $parameters, $types);
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
        return $this->executeDelete(
            [$this->mapping->primaryKey => $this->mapper->id($id)],
            Visibility::All,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validateInsert(array $data): array
    {
        $this->validateColumns($data);
        $key = $this->mapping->primaryKey;
        if (array_key_exists($key, $data)) {
            if (!is_int($data[$key]) && !is_string($data[$key])) {
                throw new InvalidArgumentException('An explicit primary key must be an integer or string.');
            }
            $data[$key] = $this->mapper->id($data[$key]);
            if (
                $this->mapping->generatedId
                && $data[$key] === 0
                && $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ) {
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
        $columns = null;
        foreach ($rows as &$row) {
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
        }
        unset($row);

        return $rows;
    }

    /** @param array<array-key, mixed> $data */
    private function validateColumns(array $data): void
    {
        foreach ($data as $column => $_) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Data must be keyed by mapped column names.');
            }
            $this->mapping->columnType($column);
        }
        if (count($data) > PredicateCompiler::MAX_PARAMETERS) {
            throw new InvalidArgumentException('Too many bound parameters for one statement.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{string, array<string, mixed>, array<string, string>}
     */
    private function insertStatement(array $data): array
    {
        if ($data === []) {
            return [
                $this->connection->getDatabasePlatform()->getEmptyIdentityInsertSQL(
                    $this->quote($this->mapping->table),
                    $this->quote($this->mapping->primaryKey),
                ),
                [],
                [],
            ];
        }

        $builder = $this->connection->createQueryBuilder()->insert($this->quote($this->mapping->table));
        foreach ($data as $column => $value) {
            $builder->setValue(
                $this->quote($column),
                $builder->createNamedParameter($value, $this->mapping->columnType($column)),
            );
        }

        return [$builder->getSQL(), $builder->getParameters(), $builder->getParameterTypes()];
    }

    /**
     * @param array<string, mixed>|Filter $criteria
     * @param array<string, mixed> $patch
     */
    private function executeUpdate(array|Filter $criteria, array $patch, Visibility $visibility): int
    {
        $builder = $this->connection->createQueryBuilder()->update($this->quote($this->mapping->table));
        foreach ($patch as $column => $value) {
            $builder->set(
                $this->quote($column),
                $builder->createNamedParameter($value, $this->mapping->columnType($column)),
            );
        }
        $matches = $this->restrictWrite($builder, $criteria, $visibility);

        return $patch === [] || !$matches ? 0 : (int) $builder->executeStatement();
    }

    /** @param array<string, mixed>|Filter $criteria */
    private function executeDelete(array|Filter $criteria, Visibility $visibility): int
    {
        $builder = $this->connection->createQueryBuilder()->delete($this->quote($this->mapping->table));

        return $this->restrictWrite($builder, $criteria, $visibility) ? (int) $builder->executeStatement() : 0;
    }

    /** @param array<string, mixed>|Filter $criteria */
    private function restrictWrite(QueryBuilder $builder, array|Filter $criteria, Visibility $visibility): bool
    {
        $condition = (new PredicateCompiler($this->connection, $this->mapping, $builder))->compile($criteria);
        if ($condition === true) {
            throw new InvalidArgumentException('A write requires a nonempty filter; use DBAL for all-row writes.');
        }
        if ($condition === false) {
            return false;
        }
        $builder->where($condition);
        if ($this->mapping->softDeleteColumn !== null && $visibility !== Visibility::All) {
            $column = $this->quote($this->mapping->softDeleteColumn);
            $builder->andWhere($visibility === Visibility::Deleted ? "$column IS NOT NULL" : "$column IS NULL");
        }

        return true;
    }

    private function quote(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }
}
