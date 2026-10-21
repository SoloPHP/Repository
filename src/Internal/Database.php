<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use LogicException;

/**
 * The connection plus platform facts. The platform is resolved on first use, so constructing
 * repositories and queries never opens a connection.
 *
 * @internal
 */
final class Database
{
    /** Budget for platforms without a known limit and for SQLite before 3.32. */
    private const int DEFAULT_PARAMETER_LIMIT = 900;

    /** MySQL plans larger IN lists as full scans with its default range-optimizer memory. */
    private const int KEY_BATCH_LIMIT = 5000;

    /** @var positive-int|null */
    private ?int $parameterLimit;

    public function __construct(public readonly Connection $connection, ?int $parameterLimit = null)
    {
        if ($parameterLimit !== null && $parameterLimit < 1) {
            throw new InvalidArgumentException('The parameter limit must be positive.');
        }

        $this->parameterLimit = $parameterLimit;
    }

    public function platform(): AbstractPlatform
    {
        return $this->connection->getDatabasePlatform();
    }

    public function dialect(): ?Dialect
    {
        return Dialect::of($this->platform());
    }

    public function requireDialect(string $feature): Dialect
    {
        return $this->dialect()
            ?? throw new LogicException($feature . ' are supported only on PostgreSQL, MySQL and SQLite.');
    }

    /**
     * Bound parameters allowed in one statement.
     *
     * @return positive-int
     */
    public function parameterLimit(): int
    {
        return $this->parameterLimit ??= match ($this->dialect()) {
            Dialect::MySQL, Dialect::PostgreSQL => 65535,
            Dialect::SQLite => version_compare($this->connection->getServerVersion(), '3.32.0', '>=')
                ? 32766
                : self::DEFAULT_PARAMETER_LIMIT,
            null => self::DEFAULT_PARAMETER_LIMIT,
        };
    }

    public function assertParameters(int $count): void
    {
        $limit = $this->parameterLimit();
        if ($count > $limit) {
            throw new InvalidArgumentException('The query exceeds the parameter limit of ' . $limit . '.');
        }
    }

    /**
     * Rows that fit into one statement next to the parameters it already binds.
     *
     * @return positive-int
     */
    public function batchSize(int $perRow = 1, int $reserved = 0): int
    {
        $rows = intdiv($this->parameterLimit() - $reserved, max(1, $perRow));
        if ($rows < 1) {
            throw new InvalidArgumentException(
                'The statement has no room for its rows within the parameter limit of ' . $this->parameterLimit() . '.',
            );
        }

        return $rows;
    }

    /**
     * Keys in one internal IN list: the room left by the statement, capped for the query planner.
     *
     * @return positive-int
     */
    public function keyBatchSize(int $reserved = 0): int
    {
        return min(self::KEY_BATCH_LIMIT, $this->batchSize(1, $reserved));
    }

    /**
     * Binds typed keys into "field IN (...)" on the query.
     *
     * @param non-empty-list<int|string> $keys
     */
    public function inList(QueryBuilder $query, string $field, string $type, array $keys): string
    {
        $this->assertParameters(count($query->getParameters()) + count($keys));
        $parameters = [];
        foreach ($keys as $key) {
            $parameters[] = $query->createNamedParameter($key, $type);
        }

        return $field . ' IN (' . implode(', ', $parameters) . ')';
    }

    /**
     * INSERT of rows with positional parameters, optionally aliasing the target table.
     *
     * @param non-empty-list<string> $columns
     */
    public function insertSql(string $table, array $columns, int $rows, ?string $alias = null): string
    {
        $tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        return 'INSERT INTO ' . $this->quote($table) . ($alias === null ? '' : ' AS ' . $this->quote($alias))
            . ' (' . implode(', ', array_map($this->quote(...), $columns)) . ') VALUES '
            . implode(', ', array_fill(0, $rows, $tuple));
    }

    public function quote(string $identifier): string
    {
        return $this->platform()->quoteSingleIdentifier($identifier);
    }

    public function qualified(string $alias, string $column): string
    {
        return $this->quote($alias) . '.' . $this->quote($column);
    }
}
