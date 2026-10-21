<?php

declare(strict_types=1);

namespace Solo\Repository;

use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use LogicException;
use Solo\Repository\Internal\Database;
use Solo\Repository\Internal\Dialect;
use Solo\Repository\Internal\Locale;
use Solo\Repository\Internal\RowMapper;
use UnexpectedValueException;

final readonly class TranslationStore
{
    private Translations $translations;

    /** @var RowMapper<object> */
    private RowMapper $mapper;

    /**
     * @internal Obtain translation stores from Repository::translations().
     * @param Mapping<object> $mapping
     */
    public function __construct(private Database $db, private Mapping $mapping)
    {
        if ($mapping->translations === null) {
            throw new LogicException('A translation store requires translation metadata.');
        }

        $this->translations = $mapping->translations;
        $this->mapper = new RowMapper($mapping, $db);
    }

    /** @return array<string, string|null>|null */
    public function get(int|string $id, string $locale): ?array
    {
        $id = $this->mapper->id($id);
        $locale = (new Locale($locale))->locale;
        $sql = 'SELECT ' . $this->fieldList()
            . ' FROM ' . $this->db->quote($this->translations->table)
            . ' WHERE ' . $this->db->quote($this->translations->foreignKey) . ' = ?'
            . ' AND ' . $this->db->quote($this->translations->localeColumn) . ' = ?';
        $result = $this->db->connection->executeQuery($sql, [$id, $locale], [$this->idType(), Types::STRING]);

        try {
            $row = $result->fetchAssociative();
            if ($row === false) {
                return null;
            }

            /** @var array<string, string|null> $converted Translated columns convert to strings or NULL. */
            $converted = $this->mapper->convert($row);

            return $converted;
        } finally {
            $result->free();
        }
    }

    /**
     * Every stored translation of the owner, keyed by locale in ascending order.
     *
     * @return array<string, array<string, string|null>>
     */
    public function all(int|string $id): array
    {
        $id = $this->mapper->id($id);
        $localeColumn = $this->translations->localeColumn;
        $locale = $this->db->quote($localeColumn);
        $sql = 'SELECT ' . $locale . ', ' . $this->fieldList()
            . ' FROM ' . $this->db->quote($this->translations->table)
            . ' WHERE ' . $this->db->quote($this->translations->foreignKey) . ' = ?'
            . ' ORDER BY ' . $locale;
        $result = $this->db->connection->executeQuery($sql, [$id], [$this->idType()]);
        try {
            $rows = $result->fetchAllAssociative();
        } finally {
            $result->free();
        }

        $translations = [];
        foreach ($rows as $row) {
            $code = $row[$localeColumn];
            if (!is_string($code)) {
                throw new UnexpectedValueException('A translation locale must be a string.');
            }
            unset($row[$localeColumn]);
            /** @var array<string, string|null> $fields Translated columns convert to strings or NULL. */
            $fields = $this->mapper->convert($row);
            $translations[$code] = $fields;
        }

        return $translations;
    }

    /**
     * Writes the patched fields of one locale. A new row gets only these fields; the others take
     * their column defaults.
     *
     * @param array<string, string|null> $patch
     */
    public function save(int|string $id, string $locale, array $patch): void
    {
        $id = $this->mapper->id($id);
        $locale = (new Locale($locale))->locale;
        $this->validatePatch($patch);
        if ($patch !== []) {
            $this->upsert($id, [$locale], $patch, true);
        }
    }

    /**
     * Inserts the translation of every locale that has none yet; existing rows stay unchanged.
     * As in save(), omitted fields of a new row take their column defaults.
     *
     * @param list<string> $locales
     * @param array<string, string|null> $values
     * @return int Rows inserted.
     */
    public function seed(int|string $id, array $locales, array $values): int
    {
        $id = $this->mapper->id($id);
        $locales = $this->distinctLocales($locales);
        $this->validatePatch($values);
        if ($locales === [] || $values === []) {
            return 0;
        }

        return $this->upsert($id, $locales, $values, false);
    }

    public function delete(int|string $id, string $locale): int
    {
        $id = $this->mapper->id($id);
        $locale = (new Locale($locale))->locale;
        $sql = 'DELETE FROM ' . $this->db->quote($this->translations->table)
            . ' WHERE ' . $this->db->quote($this->translations->foreignKey) . ' = ?'
            . ' AND ' . $this->db->quote($this->translations->localeColumn) . ' = ?';

        return (int) $this->db->connection->executeStatement(
            $sql,
            [$id, $locale],
            [$this->idType(), Types::STRING],
        );
    }

    /**
     * @param array<mixed> $locales
     * @return list<string>
     */
    private function distinctLocales(array $locales): array
    {
        $distinct = [];
        foreach ($locales as $locale) {
            if (!is_string($locale)) {
                throw new InvalidArgumentException('Locales must be strings.');
            }
            $distinct[$locale] = (new Locale($locale))->locale;
        }

        return array_values($distinct);
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

    /**
     * Inserts one row per locale with the given values; omitted fields take their column defaults.
     * A conflicting row gets the given values, or with $update false stays unchanged. Statements
     * split at the parameter limit run in one transaction.
     *
     * @param non-empty-list<string> $locales
     * @param array<string, string|null> $values
     * @return int Affected rows.
     */
    private function upsert(int|string $id, array $locales, array $values, bool $update): int
    {
        $mysql = $this->db->requireDialect('Translation upserts') === Dialect::MySQL;
        $foreignKey = $this->db->quote($this->translations->foreignKey);
        $updates = [];
        $updateParameters = [];
        $updateTypes = [];
        if ($update) {
            foreach ($values as $column => $value) {
                $quoted = $this->db->quote($column);
                if ($mysql) {
                    $updates[] = $quoted . ' = ?';
                    $updateParameters[] = $value;
                    $updateTypes[] = $this->translations->columns[$column];
                } else {
                    $updates[] = $quoted . ' = excluded.' . $quoted;
                }
            }
        }

        $localeColumn = $this->db->quote($this->translations->localeColumn);
        $conflict = match (true) {
            // MySQL reports a no-op duplicate update as zero affected rows (without CLIENT_FOUND_ROWS).
            $mysql => ' ON DUPLICATE KEY UPDATE '
                . ($update ? implode(', ', $updates) : $foreignKey . ' = ' . $foreignKey),
            default => ' ON CONFLICT (' . $foreignKey . ', ' . $localeColumn . ')'
                . ($update ? ' DO UPDATE SET ' . implode(', ', $updates) : ' DO NOTHING'),
        };
        // Keep a table named "excluded" distinct from the proposed-row pseudo-table.
        $alias = $mysql ? null : 'br_target';
        $columns = [$this->translations->foreignKey, $this->translations->localeColumn, ...array_keys($values)];

        $idType = $this->idType();
        $statements = [];
        foreach (array_chunk($locales, $this->db->batchSize(count($columns), count($updateParameters))) as $chunk) {
            $parameters = [];
            $types = [];
            foreach ($chunk as $locale) {
                array_push($parameters, $id, $locale);
                array_push($types, $idType, Types::STRING);
                foreach ($values as $column => $value) {
                    $parameters[] = $value;
                    $types[] = $this->translations->columns[$column];
                }
            }
            $statements[] = [
                $this->db->insertSql($this->translations->table, $columns, count($chunk), $alias) . $conflict,
                [...$parameters, ...$updateParameters],
                [...$types, ...$updateTypes],
            ];
        }

        $execute = function () use ($statements): int {
            $affected = 0;
            foreach ($statements as [$sql, $parameters, $types]) {
                $affected += (int) $this->db->connection->executeStatement($sql, $parameters, $types);
            }

            return $affected;
        };

        return count($statements) > 1 ? $this->db->connection->transactional($execute) : $execute();
    }

    private function fieldList(): string
    {
        return implode(', ', array_map($this->db->quote(...), array_keys($this->translations->columns)));
    }

    private function idType(): string
    {
        return $this->mapping->columnType($this->mapping->primaryKey);
    }
}
