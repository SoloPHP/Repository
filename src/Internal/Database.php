<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
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

    /** Row locks end with the transaction that holds them; outside one they would end with their statement. */
    public function assertTransaction(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new LogicException('Row locks require an active transaction.');
        }
    }

    /**
     * Runs a SELECT that locks the rows it reads until the transaction ends. Given the alias of
     * one table, PostgreSQL and MySQL 8 lock only the rows of that table (FOR UPDATE OF), as
     * PostgreSQL cannot lock the nullable side of an outer join; MariaDB and MySQL 5.7 have no OF
     * and also lock the rows the statement joins. SQLite has no row locks and runs the plain SELECT.
     */
    public function executeLocked(QueryBuilder $query, ?string $alias = null): Result
    {
        $this->assertTransaction();
        $dialect = $this->dialect();
        if ($dialect === Dialect::SQLite) {
            return $query->executeQuery();
        }
        if ($alias !== null && ($dialect === Dialect::PostgreSQL || $this->platform() instanceof MySQL80Platform)) {
            return $this->connection->executeQuery(
                $query->getSQL() . ' FOR UPDATE OF ' . $this->quote($alias),
                $query->getParameters(),
                $query->getParameterTypes(),
            );
        }

        return (clone $query)->forUpdate()->executeQuery();
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

    /**
     * What turns an INSERT into an upsert: on a conflict over the key, the given columns of the
     * existing row take their new values, or with none the row stays unchanged. MySQL binds the
     * new values again after the rows; PostgreSQL and SQLite read them from the proposed row,
     * which the INSERT keeps apart from a table named "excluded" by aliasing its own table.
     *
     * @param string $feature What the upsert writes, for the error on a platform without upserts.
     * @param non-empty-list<string> $key The conflict target; MySQL reacts to a conflict over any unique index.
     * @param array<string, array{mixed, string}> $updates The new value and DBAL type of each updated column.
     * @return array{?string, string, list<mixed>, list<string>} The alias for insertSql(), the clause that
     *         follows the INSERT, and the parameters with types that the clause binds.
     */
    public function upsertClause(string $feature, array $key, array $updates): array
    {
        if ($this->requireDialect($feature) === Dialect::MySQL) {
            $assignments = [];
            $parameters = [];
            $types = [];
            foreach ($updates as $column => [$value, $type]) {
                $assignments[] = $this->quote($column) . ' = ?';
                $parameters[] = $value;
                $types[] = $type;
            }
            // Assigning a key column to itself leaves the existing row unchanged, and MySQL then
            // reports it as unaffected (without CLIENT_FOUND_ROWS): affected rows count insertions.
            $unchanged = $this->quote($key[0]) . ' = ' . $this->quote($key[0]);

            return [
                null,
                ' ON DUPLICATE KEY UPDATE ' . ($assignments === [] ? $unchanged : implode(', ', $assignments)),
                $parameters,
                $types,
            ];
        }

        $assignments = [];
        foreach (array_keys($updates) as $column) {
            $assignments[] = $this->quote($column) . ' = excluded.' . $this->quote($column);
        }

        return [
            'br_target',
            ' ON CONFLICT (' . implode(', ', array_map($this->quote(...), $key)) . ')'
                . ($assignments === [] ? ' DO NOTHING' : ' DO UPDATE SET ' . implode(', ', $assignments)),
            [],
            [],
        ];
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
