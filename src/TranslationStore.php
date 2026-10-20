<?php

declare(strict_types=1);

namespace Solo\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use LogicException;
use Solo\Repository\Internal\Locale;
use Solo\Repository\Internal\PredicateCompiler;
use Solo\Repository\Internal\RowMapper;

final readonly class TranslationStore
{
    private Translations $translations;
    private RowMapper $mapper;

    public function __construct(private Connection $connection, private Mapping $mapping)
    {
        if ($mapping->translations === null) {
            throw new LogicException('A translation store requires translation metadata.');
        }

        $this->translations = $mapping->translations;
        $this->mapper = new RowMapper($mapping, $connection->getDatabasePlatform());
    }

    /** @return array<string, string|null>|null */
    public function get(int|string $id, string $locale): ?array
    {
        $id = $this->mapper->id($id);
        $locale = (new Locale($locale))->locale;
        $sql = 'SELECT ' . implode(', ', array_map($this->quote(...), array_keys($this->translations->columns)))
            . ' FROM ' . $this->quote($this->translations->table)
            . ' WHERE ' . $this->quote($this->translations->foreignKey) . ' = ?'
            . ' AND ' . $this->quote($this->translations->localeColumn) . ' = ?';
        $result = $this->connection->executeQuery($sql, [$id, $locale], [$this->idType(), Types::STRING]);

        try {
            $row = $result->fetchAssociative();
            if ($row === false) {
                return null;
            }

            $platform = $this->connection->getDatabasePlatform();
            foreach ($this->translations->columns as $column => $type) {
                $row[$column] = Type::getType($type)->convertToPHPValue($row[$column], $platform);
            }

            return $row;
        } finally {
            $result->free();
        }
    }

    /** @param array<string, string|null> $patch */
    public function save(int|string $id, string $locale, array $patch): void
    {
        $id = $this->mapper->id($id);
        $locale = (new Locale($locale))->locale;
        $this->validatePatch($patch);
        if ($patch === []) {
            return;
        }

        $platform = $this->connection->getDatabasePlatform();
        $mysql = $platform instanceof MySQLPlatform;
        if (!$mysql && !$platform instanceof PostgreSQLPlatform && !$platform instanceof SQLitePlatform) {
            throw new LogicException('Translation upserts are supported only on PostgreSQL, MySQL and SQLite.');
        }

        $parameterCount = 2 + count($this->translations->columns) + ($mysql ? count($patch) : 0);
        if ($parameterCount > PredicateCompiler::MAX_PARAMETERS) {
            throw new InvalidArgumentException(
                'The translation upsert exceeds the parameter limit of ' . PredicateCompiler::MAX_PARAMETERS . '.',
            );
        }

        $columns = [$this->translations->foreignKey, $this->translations->localeColumn];
        $parameters = [$id, $locale];
        $types = [$this->idType(), Types::STRING];
        foreach ($this->translations->columns as $column => $type) {
            $columns[] = $column;
            $parameters[] = $patch[$column] ?? null;
            $types[] = $type;
        }

        $updates = [];
        foreach ($patch as $column => $value) {
            $quoted = $this->quote($column);
            if ($mysql) {
                $updates[] = $quoted . ' = ?';
                $parameters[] = $value;
                $types[] = $this->translations->columns[$column];
            } else {
                $updates[] = $quoted . ' = excluded.' . $quoted;
            }
        }

        // Keep a table named "excluded" distinct from the proposed-row pseudo-table.
        $sql = 'INSERT INTO ' . $this->quote($this->translations->table)
            . ($mysql ? '' : ' AS ' . $this->quote('br_target'))
            . ' (' . implode(', ', array_map($this->quote(...), $columns)) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $sql .= $mysql
            ? ' ON DUPLICATE KEY UPDATE '
            : ' ON CONFLICT (' . $this->quote($this->translations->foreignKey) . ', '
                . $this->quote($this->translations->localeColumn) . ') DO UPDATE SET ';

        $this->connection->executeStatement($sql . implode(', ', $updates), $parameters, $types);
    }

    public function delete(int|string $id, string $locale): int
    {
        $id = $this->mapper->id($id);
        $locale = (new Locale($locale))->locale;
        $sql = 'DELETE FROM ' . $this->quote($this->translations->table)
            . ' WHERE ' . $this->quote($this->translations->foreignKey) . ' = ?'
            . ' AND ' . $this->quote($this->translations->localeColumn) . ' = ?';

        return (int) $this->connection->executeStatement($sql, [$id, $locale], [$this->idType(), Types::STRING]);
    }

    /** @param array<array-key, mixed> $patch */
    private function validatePatch(array $patch): void
    {
        foreach ($patch as $column => $value) {
            if (!is_string($column) || !array_key_exists($column, $this->translations->columns)) {
                throw new InvalidArgumentException(
                    'A translation patch may contain only configured translated fields.',
                );
            }

            if ($value !== null && !is_string($value)) {
                throw new InvalidArgumentException('Translated values must be strings or NULL.');
            }
        }
    }

    private function idType(): string
    {
        return $this->mapping->columnType($this->mapping->primaryKey);
    }

    private function quote(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }
}
