<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;
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

    /**
     * Placeholders the query binds once DBAL expands its list parameters: a list counts every value.
     */
    public static function boundCount(QueryBuilder $query): int
    {
        $types = $query->getParameterTypes();
        $count = 0;
        foreach ($query->getParameters() as $key => $value) {
            $count += is_array($value) && ($types[$key] ?? null) instanceof ArrayParameterType ? count($value) : 1;
        }

        return $count;
    }

    /**
     * A list of typed values as one DBAL list parameter: the values converted to their database form and the
     * list type of their binding, or NULL when DBAL has no list type for that binding (booleans, large objects).
     * One list parameter costs a fraction of one named parameter per value, and DBAL expands it to the same SQL.
     *
     * @param list<mixed> $values
     * @return array{list<mixed>, ArrayParameterType}|null
     */
    public function listParameter(string $type, array $values): ?array
    {
        $dbalType = Type::getType($type);
        $listType = match ($dbalType->getBindingType()) {
            ParameterType::INTEGER => ArrayParameterType::INTEGER,
            ParameterType::STRING => ArrayParameterType::STRING,
            ParameterType::ASCII => ArrayParameterType::ASCII,
            ParameterType::BINARY => ArrayParameterType::BINARY,
            default => null,
        };
        if ($listType === null) {
            return null;
        }

        $platform = $this->platform();
        $converted = [];
        foreach ($values as $value) {
            $converted[] = $dbalType->convertToDatabaseValue($value, $platform);
        }

        return [$converted, $listType];
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
        $this->assertParameters(self::boundCount($query) + count($keys));
        $list = $this->listParameter($type, $keys);
        if ($list !== null) {
            return $field . ' IN (' . $query->createNamedParameter($list[0], $list[1]) . ')';
        }

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
