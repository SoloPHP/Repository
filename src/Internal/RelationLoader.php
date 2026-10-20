<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsTo;
use Solo\Repository\Relations\BelongsToMany;
use Solo\Repository\Relations\HasMany;
use Solo\Repository\Relations\HasOne;
use UnexpectedValueException;

/** @internal */
final readonly class RelationLoader
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<PreparedLoad> */
    public function prepare(Mapping $mapping, LoadPlan $plan, ?Locale $inherited): array
    {
        $prepared = [];
        foreach ($plan->nodes as $name => $node) {
            $relation = $mapping->relation($name);
            $target = $relation->resolve($this->connection, $mapping)->mapping();
            $executor = new ReadExecutor($this->connection, $target, $node->load->visibility, $inherited);
            $query = $executor->select(
                array_keys($target->readColumns()),
                $node->load->criteria,
                $node->load->orderBy,
            );
            $batchSize = PredicateCompiler::MAX_PARAMETERS - count($query->getParameters());
            if ($batchSize < 1) {
                throw new InvalidArgumentException('Relation loading needs an available parameter for its keys.');
            }

            $prepared[] = new PreparedLoad(
                $relation,
                $target,
                $executor,
                $node->load,
                $this->prepare($target, $node->children, $executor->locale()),
                $batchSize,
            );
        }

        return $prepared;
    }

    /**
     * @param list<Record> $records
     * @param list<PreparedLoad> $prepared
     */
    public function load(Mapping $mapping, array $records, array $prepared): void
    {
        if ($records === []) {
            return;
        }

        foreach ($prepared as $load) {
            $ownerMapper = $this->mapper($mapping);
            $targetMapper = $this->mapper($load->mapping);
            $ids = $this->keys(
                $records,
                $mapping->primaryKey,
                $ownerMapper,
                $load->relation instanceof BelongsTo ? $load->relation->foreignKey : null,
            );
            $groups = $load->relation instanceof BelongsToMany
                ? $this->linked($mapping, $ids, $load, $ownerMapper, $targetMapper)
                : $this->direct($mapping, $ids, $load);
            $many = $load->relation instanceof HasMany || $load->relation instanceof BelongsToMany;

            foreach ($records as $record) {
                $key = $this->key($ownerMapper->id($record->row[$mapping->primaryKey]));
                $group = $groups[$key] ?? [];
                /** @var mixed $entity */
                $entity = ($load->relation->assign)($record->entity, $many ? $group : ($group[0] ?? null));
                if (!is_object($entity)) {
                    throw new UnexpectedValueException('A relation assign callback must return an object.');
                }
                $record->entity = $entity;
            }
        }
    }

    /**
     * @param list<int|string> $ids
     * @return array<string, list<object>>
     */
    private function direct(Mapping $owner, array $ids, PreparedLoad $load): array
    {
        $relation = $load->relation;
        if (!$relation instanceof BelongsTo && !$relation instanceof HasOne && !$relation instanceof HasMany) {
            throw new InvalidArgumentException('Unsupported relation definition.');
        }
        $groups = [];
        foreach (array_chunk($ids, $load->batchSize) as $chunk) {
            $query = $load->executor->selectRelated(
                $relation,
                $owner,
                $chunk,
                $load->load->criteria,
                $load->load->orderBy,
            );
            $related = $load->executor->records($query, $owner);
            $this->load($load->mapping, $related, $load->children);
            foreach ($related as $record) {
                if ($record->ownerId === null) {
                    throw new UnexpectedValueException('A related result must contain its owner ID.');
                }
                $key = $this->key($record->ownerId);
                if ($relation instanceof HasOne && isset($groups[$key])) {
                    throw new UnexpectedValueException('A HasOne relation matched more than one record.');
                }
                $groups[$key][] = $record->entity;
            }
        }

        return $groups;
    }

    /**
     * @param list<int|string> $ids
     * @return array<string, list<object>>
     */
    private function linked(
        Mapping $owner,
        array $ids,
        PreparedLoad $load,
        RowMapper $ownerMapper,
        RowMapper $targetMapper,
    ): array {
        $relation = $load->relation;
        if (!$relation instanceof BelongsToMany) {
            throw new InvalidArgumentException('Expected a many-to-many relation.');
        }
        $ownerType = $owner->columnType($owner->primaryKey);
        $groups = [];
        foreach (array_chunk($ids, $load->batchSize) as $chunk) {
            $pivot = $this->connection->createQueryBuilder()
                ->select(
                    $this->qualified('br_owner_table', $owner->primaryKey) . ' AS ' . $this->quote('br_owner'),
                    $this->qualified('br_target_table', $load->mapping->primaryKey)
                        . ' AS ' . $this->quote('br_related'),
                )
                ->from($this->quote($relation->pivotTable), 'br_pivot')
                ->innerJoin(
                    'br_pivot',
                    $this->quote($owner->table),
                    'br_owner_table',
                    $this->qualified('br_owner_table', $owner->primaryKey)
                        . ' = ' . $this->qualified('br_pivot', $relation->foreignPivotKey),
                )
                ->innerJoin(
                    'br_pivot',
                    $this->quote($load->mapping->table),
                    'br_target_table',
                    $this->qualified('br_target_table', $load->mapping->primaryKey)
                        . ' = ' . $this->qualified('br_pivot', $relation->relatedPivotKey),
                );
            $parameters = [];
            foreach ($chunk as $id) {
                $parameters[] = $pivot->createNamedParameter($id, $ownerType);
            }
            $pivot->where(
                $this->qualified('br_owner_table', $owner->primaryKey) . ' IN (' . implode(', ', $parameters) . ')'
            );
            $ownersByTarget = [];
            foreach ($load->executor->fetchRows($pivot) as $row) {
                $targetKey = $this->key($targetMapper->id($row['br_related']));
                $ownerKey = $this->key($ownerMapper->id($row['br_owner']));
                $ownersByTarget[$targetKey][$ownerKey] = true;
            }
            if ($ownersByTarget === []) {
                continue;
            }

            $query = $load->executor->selectLinked(
                $relation,
                $owner,
                $chunk,
                $load->load->criteria,
                $load->load->orderBy,
            );
            $related = $load->executor->records($query);
            $this->load($load->mapping, $related, $load->children);
            foreach ($related as $record) {
                $targetKey = $this->key($targetMapper->id($record->row[$load->mapping->primaryKey]));
                foreach ($ownersByTarget[$targetKey] ?? [] as $ownerKey => $_) {
                    $groups[$ownerKey][] = $record->entity;
                }
            }
        }

        return $groups;
    }

    /**
     * @param list<Record> $records
     * @return list<int|string>
     */
    private function keys(
        array $records,
        string $column,
        RowMapper $mapper,
        ?string $requiredColumn = null,
    ): array {
        $keys = [];
        foreach ($records as $record) {
            if ($requiredColumn !== null && $record->row[$requiredColumn] === null) {
                continue;
            }
            $value = $record->row[$column];
            if ($value !== null) {
                $id = $mapper->id($value);
                $keys[$this->key($id)] = $id;
            }
        }

        return array_values($keys);
    }

    private function key(int|string $id): string
    {
        return 'key:' . $id;
    }

    private function mapper(Mapping $mapping): RowMapper
    {
        return new RowMapper($mapping, $this->connection->getDatabasePlatform());
    }

    private function quote(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }

    private function qualified(string $alias, string $column): string
    {
        return $this->quote($alias) . '.' . $this->quote($column);
    }
}
