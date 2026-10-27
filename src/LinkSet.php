<?php

declare(strict_types=1);

namespace Solo\Repository;

use InvalidArgumentException;
use Solo\Repository\Internal\Database;
use Solo\Repository\Internal\KeyType;
use Solo\Repository\Internal\RowMapper;
use Solo\Repository\Relations\BelongsToMany;

/**
 * Physical pivot writes. A pair another writer stored first is skipped, not raised; transactions
 * provide rollback, not serialization of concurrent edits to one set.
 */
final readonly class LinkSet
{
    /** SQLite allows 500 compound SELECT terms by default (SQLITE_MAX_COMPOUND_SELECT). */
    private const int COMPOUND_SELECT_BATCH = 450;

    /** @var BelongsToMany<object, object> */
    private BelongsToMany $relation;

    /** @var Mapping<object> */
    private Mapping $target;

    /** Primary key of the owner table. */
    private string $ownerKey;

    /** Primary key of the target table. */
    private string $targetKey;

    /** @var RowMapper<object> */
    private RowMapper $ownerMapper;

    /** @var RowMapper<object> */
    private RowMapper $targetMapper;

    /**
     * @internal Obtain link sets from Repository::links().
     * @param Mapping<object> $mapping
     */
    public function __construct(private Database $db, private Mapping $mapping, string $relation)
    {
        $definition = $mapping->relation($relation);
        if (!$definition instanceof BelongsToMany) {
            throw new InvalidArgumentException('LinkSet requires a BelongsToMany relation.');
        }
        $this->relation = $definition;
        $this->target = $definition->resolve($mapping);
        $this->ownerKey = $definition->ownerKey($mapping);
        $this->targetKey = $definition->targetKey($this->target);
        $this->ownerMapper = new RowMapper($mapping, $db);
        $this->targetMapper = new RowMapper($this->target, $db);
    }

    /** @param list<int|string> $ids */
    public function attach(int|string $id, array $ids): void
    {
        $id = $this->ownerMapper->id($id);
        $ids = $this->targetMapper->ids($ids);
        if ($ids === []) {
            return;
        }

        $this->db->connection->transactional(function () use ($id, $ids): void {
            $id = $this->canonicalOwnerId($id);
            $ids = $this->canonicalTargetIds($ids);
            $this->insertLinks($id, array_diff_key($ids, $this->existingIds($id)));
        });
    }

    /** @param list<int|string> $ids */
    public function detach(int|string $id, array $ids): void
    {
        $id = $this->ownerMapper->id($id);
        $ids = $this->targetMapper->ids($ids);
        if ($ids === []) {
            return;
        }

        $this->db->connection->transactional(fn () => $this->deleteLinks($id, $ids));
    }

    /** @param list<int|string> $ids */
    public function sync(int|string $id, array $ids): void
    {
        $id = $this->ownerMapper->id($id);
        $ids = $this->targetMapper->ids($ids);

        $this->db->connection->transactional(function () use ($id, $ids): void {
            if ($ids === []) {
                [$condition, $parameters, $types] = $this->ownerCondition($id);
                $this->db->connection->executeStatement(
                    'DELETE FROM ' . $this->db->quote($this->relation->pivotTable) . ' WHERE ' . $condition,
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

    /** @return array<string, int|string> */
    private function existingIds(int|string $id): array
    {
        [$condition, $parameters, $types] = $this->ownerCondition($id, 'br_link_pivot');
        $physical = $this->db->qualified('br_link_pivot', $this->relation->relatedPivotKey);
        $canonical = $this->db->qualified('br_link_target', $this->targetKey);
        $result = $this->db->connection->executeQuery(
            'SELECT COALESCE(' . $canonical . ', ' . $physical . ')'
                . ' FROM ' . $this->db->quote($this->relation->pivotTable) . ' ' . $this->db->quote('br_link_pivot')
                . ' LEFT JOIN ' . $this->db->quote($this->target->table) . ' ' . $this->db->quote('br_link_target')
                . ' ON ' . $canonical . ' = ' . $physical
                . ' WHERE ' . $condition,
            $parameters,
            $types,
        );
        try {
            return $this->targetMapper->ids($result->fetchFirstColumn());
        } finally {
            $result->free();
        }
    }

    private function canonicalOwnerId(int|string $id): int|string
    {
        if ($this->ownerMapper->keyType() !== KeyType::String) {
            return $id;
        }
        $canonical = $this->db->connection->fetchOne(
            'SELECT ' . $this->db->quote($this->ownerKey)
                . ' FROM ' . $this->db->quote($this->mapping->table)
                . ' WHERE ' . $this->db->quote($this->ownerKey) . ' = ?',
            [$id],
            [$this->ownerType()],
        );

        return $canonical === false ? $id : $this->ownerMapper->rowId($canonical);
    }

    /**
     * @param array<string, int|string> $ids
     * @return array<string, int|string>
     */
    private function canonicalTargetIds(array $ids): array
    {
        if ($this->targetMapper->keyType() !== KeyType::String) {
            return $ids;
        }

        $canonical = [];
        $size = min(self::COMPOUND_SELECT_BATCH, $this->db->parameterLimit());
        foreach (array_chunk($ids, $size) as $chunk) {
            // The empty first branch gives PostgreSQL UUID parameters their column type.
            $input = 'SELECT ' . $this->db->quote($this->targetKey) . ' AS link_id FROM '
                . $this->db->quote($this->target->table) . ' WHERE 1 = 0'
                . str_repeat(' UNION ALL SELECT ?', count($chunk));
            $target = $this->db->qualified('br_link_target', $this->targetKey);
            $candidate = $this->db->qualified('br_link_input', 'link_id');
            $result = $this->db->connection->executeQuery(
                'SELECT COALESCE(' . $target . ', ' . $candidate . ')'
                    . ' FROM (' . $input . ') ' . $this->db->quote('br_link_input')
                    . ' LEFT JOIN ' . $this->db->quote($this->target->table) . ' ' . $this->db->quote('br_link_target')
                    . ' ON ' . $target . ' = ' . $candidate,
                $chunk,
                array_fill(0, count($chunk), $this->targetType()),
            );
            try {
                $canonical += $this->targetMapper->ids($result->fetchFirstColumn());
            } finally {
                $result->free();
            }
        }

        return $canonical;
    }

    /** @return array{string, list<int|string>, list<string>} */
    private function ownerCondition(int|string $id, ?string $alias = null): array
    {
        $foreign = $this->db->qualified($alias ?? $this->relation->pivotTable, $this->relation->foreignPivotKey);
        $type = $this->ownerType();
        if ($this->ownerMapper->keyType() !== KeyType::String) {
            return [$foreign . ' = ?', [$id], [$type]];
        }

        $ownerAlias = $this->relation->pivotTable === 'br_link_owner' ? 'br_link_owner_1' : 'br_link_owner';
        $primary = $this->db->qualified($ownerAlias, $this->ownerKey);
        $condition = 'EXISTS (SELECT 1 FROM ' . $this->db->quote($this->mapping->table)
            . ' ' . $this->db->quote($ownerAlias)
            . ' WHERE ' . $primary . ' = ' . $foreign . ' AND ' . $primary . ' = ?)';

        return [$condition, [$id], [$type]];
    }

    /**
     * Inserts the pairs the operation found missing. Another writer may store one of them after
     * that read: the pair then conflicts over the pivot's required UNIQUE pair and is skipped,
     * since it is the link the caller asked for. Every other error, a foreign-key violation
     * among them, still propagates. Platforms without upserts insert the pairs as they are.
     *
     * @param array<string, int|string> $ids
     */
    private function insertLinks(int|string $id, array $ids): void
    {
        $columns = [$this->relation->foreignPivotKey, $this->relation->relatedPivotKey];
        [$alias, $skipStored] = $this->db->dialect() === null
            ? [null, '']
            : $this->db->upsertClause('Link inserts', $columns, []);
        $ownerType = $this->ownerType();
        $targetType = $this->targetType();
        foreach (array_chunk($ids, $this->db->batchSize(2)) as $chunk) {
            $values = [];
            $types = [];
            foreach ($chunk as $targetId) {
                array_push($values, $id, $targetId);
                array_push($types, $ownerType, $targetType);
            }
            $this->db->connection->executeStatement(
                $this->db->insertSql($this->relation->pivotTable, $columns, count($chunk), $alias) . $skipStored,
                $values,
                $types,
            );
        }
    }

    /** @param array<string, int|string> $ids */
    private function deleteLinks(int|string $id, array $ids): void
    {
        [$condition, $parameters, $types] = $this->ownerCondition($id);
        $stringKey = $this->targetMapper->keyType() === KeyType::String;
        foreach (array_chunk($ids, $this->db->keyBatchSize(count($parameters))) as $chunk) {
            $foreign = $this->db->qualified($this->relation->pivotTable, $this->relation->relatedPivotKey);
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $targetCondition = $foreign . ' IN (' . $placeholders . ')';
            if ($stringKey) {
                $alias = $this->relation->pivotTable === 'br_link_target' ? 'br_link_target_1' : 'br_link_target';
                $primary = $this->db->qualified($alias, $this->targetKey);
                $target = 'SELECT 1 FROM ' . $this->db->quote($this->target->table) . ' ' . $this->db->quote($alias)
                    . ' WHERE ' . $primary . ' = ' . $foreign;
                $targetCondition = 'EXISTS (' . $target . ' AND ' . $primary . ' IN (' . $placeholders . '))';
            }
            $this->db->connection->executeStatement(
                'DELETE FROM ' . $this->db->quote($this->relation->pivotTable)
                    . ' WHERE ' . $condition . ' AND ' . $targetCondition,
                [...$parameters, ...$chunk],
                [...$types, ...array_fill(0, count($chunk), $this->targetType())],
            );
        }
    }

    private function ownerType(): string
    {
        return $this->mapping->columnType($this->ownerKey);
    }

    private function targetType(): string
    {
        return $this->target->columnType($this->targetKey);
    }
}
