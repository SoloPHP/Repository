<?php

declare(strict_types=1);

namespace Solo\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Solo\Repository\Internal\PredicateCompiler;
use Solo\Repository\Internal\RowMapper;
use Solo\Repository\Relations\BelongsToMany;

/**
 * Physical pivot writes. Transactions provide rollback, not concurrent set serialization.
 */
final readonly class LinkSet
{
    private BelongsToMany $relation;
    private Mapping $target;
    private RowMapper $ownerMapper;
    private RowMapper $targetMapper;

    public function __construct(private Connection $connection, private Mapping $mapping, string $relation)
    {
        $definition = $mapping->relation($relation);
        if (!$definition instanceof BelongsToMany) {
            throw new InvalidArgumentException('LinkSet requires a BelongsToMany relation.');
        }
        $this->relation = $definition;
        $this->target = $definition->resolve($connection, $mapping)->mapping();
        $platform = $connection->getDatabasePlatform();
        $this->ownerMapper = new RowMapper($mapping, $platform);
        $this->targetMapper = new RowMapper($this->target, $platform);
    }

    /** @param list<int|string> $ids */
    public function attach(int|string $id, array $ids): void
    {
        $id = $this->ownerMapper->id($id);
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return;
        }

        $this->connection->transactional(function () use ($id, $ids): void {
            $id = $this->canonicalOwnerId($id);
            $ids = $this->canonicalTargetIds($ids);
            $this->insertLinks($id, array_diff_key($ids, $this->existingIds($id)));
        });
    }

    /** @param list<int|string> $ids */
    public function detach(int|string $id, array $ids): void
    {
        $id = $this->ownerMapper->id($id);
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return;
        }

        $this->connection->transactional(fn () => $this->deleteLinks($id, $ids));
    }

    /** @param list<int|string> $ids */
    public function sync(int|string $id, array $ids): void
    {
        $id = $this->ownerMapper->id($id);
        $ids = $this->normalizeIds($ids);

        $this->connection->transactional(function () use ($id, $ids): void {
            if ($ids === []) {
                [$condition, $parameters, $types] = $this->ownerCondition($id);
                $this->connection->executeStatement(
                    'DELETE FROM ' . $this->quote($this->relation->pivotTable)
                        . ' WHERE ' . $condition,
                    $parameters,
                    $types,
                );

                return;
            }
            $id = $this->canonicalOwnerId($id);
            $ids = $this->canonicalTargetIds($ids);
            $existing = $this->existingIds($id);
            $this->deleteLinks($id, array_diff_key($existing, $ids));
            $this->insertLinks($id, array_diff_key($ids, $existing));
        });
    }

    /**
     * @param array<mixed> $ids
     * @return array<int|string, int|string>
     */
    private function normalizeIds(array $ids): array
    {
        if (!array_is_list($ids)) {
            throw new InvalidArgumentException('Related IDs must be a list.');
        }
        $normalized = [];
        foreach ($ids as $id) {
            if (!is_int($id) && !is_string($id)) {
                throw new InvalidArgumentException('Related IDs must be integers or strings.');
            }
            $id = $this->targetMapper->id($id);
            $normalized[(string) $id] = $id;
        }

        return $normalized;
    }

    /** @return array<int|string, int|string> */
    private function existingIds(int|string $id): array
    {
        [$condition, $parameters, $types] = $this->ownerCondition($id, 'br_link_pivot');
        $physical = $this->qualified('br_link_pivot', $this->relation->relatedPivotKey);
        $canonical = $this->qualified('br_link_target', $this->target->primaryKey);
        $result = $this->connection->executeQuery(
            'SELECT COALESCE(' . $canonical . ', ' . $physical . ')'
                . ' FROM ' . $this->quote($this->relation->pivotTable) . ' ' . $this->quote('br_link_pivot')
                . ' LEFT JOIN ' . $this->quote($this->target->table) . ' ' . $this->quote('br_link_target')
                . ' ON ' . $canonical . ' = ' . $physical
                . ' WHERE ' . $condition,
            $parameters,
            $types,
        );
        try {
            return $this->normalizeIds($result->fetchFirstColumn());
        } finally {
            $result->free();
        }
    }

    private function canonicalOwnerId(int|string $id): int|string
    {
        if (!$this->hasStringKey($this->ownerType())) {
            return $id;
        }
        $canonical = $this->connection->fetchOne(
            'SELECT ' . $this->quote($this->mapping->primaryKey)
                . ' FROM ' . $this->quote($this->mapping->table)
                . ' WHERE ' . $this->quote($this->mapping->primaryKey) . ' = ?',
            [$id],
            [$this->ownerType()],
        );

        return $canonical === false ? $id : $this->ownerMapper->id($canonical);
    }

    /**
     * @param array<int|string, int|string> $ids
     * @return array<int|string, int|string>
     */
    private function canonicalTargetIds(array $ids): array
    {
        if (!$this->hasStringKey($this->targetType())) {
            return $ids;
        }

        $canonical = [];
        foreach (array_chunk($ids, 450) as $chunk) {
            // The empty first branch gives PostgreSQL UUID parameters their column type.
            $input = 'SELECT ' . $this->quote($this->target->primaryKey) . ' AS link_id FROM '
                . $this->quote($this->target->table) . ' WHERE 1 = 0'
                . str_repeat(' UNION ALL SELECT ?', count($chunk));
            $target = $this->qualified('br_link_target', $this->target->primaryKey);
            $candidate = $this->qualified('br_link_input', 'link_id');
            $result = $this->connection->executeQuery(
                'SELECT COALESCE(' . $target . ', ' . $candidate . ')'
                    . ' FROM (' . $input . ') ' . $this->quote('br_link_input')
                    . ' LEFT JOIN ' . $this->quote($this->target->table) . ' ' . $this->quote('br_link_target')
                    . ' ON ' . $target . ' = ' . $candidate,
                $chunk,
                array_fill(0, count($chunk), $this->targetType()),
            );
            try {
                $canonical += $this->normalizeIds($result->fetchFirstColumn());
            } finally {
                $result->free();
            }
        }

        return $canonical;
    }

    /** @return array{string, list<int|string>, list<string>} */
    private function ownerCondition(int|string $id, ?string $alias = null): array
    {
        $foreign = $this->qualified($alias ?? $this->relation->pivotTable, $this->relation->foreignPivotKey);
        $type = $this->ownerType();
        if (!$this->hasStringKey($type)) {
            return [$foreign . ' = ?', [$id], [$type]];
        }

        $ownerAlias = $this->relation->pivotTable === 'br_link_owner' ? 'br_link_owner_1' : 'br_link_owner';
        $primary = $this->qualified($ownerAlias, $this->mapping->primaryKey);
        $condition = 'EXISTS (SELECT 1 FROM ' . $this->quote($this->mapping->table) . ' ' . $this->quote($ownerAlias)
            . ' WHERE ' . $primary . ' = ' . $foreign . ' AND ' . $primary . ' = ?)';

        return [$condition, [$id], [$type]];
    }

    /** @param array<int|string, int|string> $ids */
    private function insertLinks(int|string $id, array $ids): void
    {
        foreach (array_chunk($ids, intdiv(PredicateCompiler::MAX_PARAMETERS, 2)) as $chunk) {
            $values = [];
            $types = [];
            foreach ($chunk as $targetId) {
                $values[] = $id;
                $values[] = $targetId;
                $types[] = $this->ownerType();
                $types[] = $this->targetType();
            }
            $this->connection->executeStatement(
                'INSERT INTO ' . $this->quote($this->relation->pivotTable)
                    . ' (' . $this->quote($this->relation->foreignPivotKey)
                    . ', ' . $this->quote($this->relation->relatedPivotKey) . ') VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?)')),
                $values,
                $types,
            );
        }
    }

    /** @param array<int|string, int|string> $ids */
    private function deleteLinks(int|string $id, array $ids): void
    {
        [$condition, $parameters, $types] = $this->ownerCondition($id);
        $stringKey = $this->hasStringKey($this->targetType());
        $size = PredicateCompiler::MAX_PARAMETERS - count($parameters);
        foreach (array_chunk($ids, $size) as $chunk) {
            $foreign = $this->qualified($this->relation->pivotTable, $this->relation->relatedPivotKey);
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $targetCondition = $foreign . ' IN (' . $placeholders . ')';
            if ($stringKey) {
                $alias = $this->relation->pivotTable === 'br_link_target' ? 'br_link_target_1' : 'br_link_target';
                $primary = $this->qualified($alias, $this->target->primaryKey);
                $target = 'SELECT 1 FROM ' . $this->quote($this->target->table) . ' ' . $this->quote($alias)
                    . ' WHERE ' . $primary . ' = ' . $foreign;
                $targetCondition = 'EXISTS (' . $target . ' AND ' . $primary . ' IN (' . $placeholders . '))';
            }
            $this->connection->executeStatement(
                'DELETE FROM ' . $this->quote($this->relation->pivotTable)
                    . ' WHERE ' . $condition . ' AND ' . $targetCondition,
                [...$parameters, ...$chunk],
                [...$types, ...array_fill(0, count($chunk), $this->targetType())],
            );
        }
    }

    private function ownerType(): string
    {
        return $this->mapping->columnType($this->mapping->primaryKey);
    }

    private function targetType(): string
    {
        return $this->target->columnType($this->target->primaryKey);
    }

    private function hasStringKey(string $type): bool
    {
        return in_array($type, [Types::STRING, Types::ASCII_STRING, Types::GUID], true);
    }

    private function qualified(string $alias, string $column): string
    {
        return $this->quote($alias) . '.' . $this->quote($column);
    }

    private function quote(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }
}
