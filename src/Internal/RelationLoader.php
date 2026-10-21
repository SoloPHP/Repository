<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsToMany;
use UnexpectedValueException;

/** @internal */
final readonly class RelationLoader
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param Mapping<object> $mapping
     * @return list<PreparedLoad>
     */
    public function prepare(Mapping $mapping, LoadPlan $plan, ?Locale $inherited): array
    {
        $prepared = [];
        foreach ($plan->nodes as $name => $node) {
            $relation = $mapping->relation($name);
            $target = $relation->resolve($mapping);
            $executor = new ReadExecutor($this->db, $target, $node->load->visibility, $inherited);
            $query = $executor->selectAll($node->load->criteria, $node->load->orderBy ?: $relation->orderBy);

            $prepared[] = new PreparedLoad(
                $relation,
                $executor,
                $query,
                $this->prepare($target, $node->children, $executor->locale()),
                $this->db->keyBatchSize(count($query->getParameters())),
            );
        }

        return $prepared;
    }

    /**
     * @param ReadExecutor<object> $owner
     * @param list<Record<object>> $records
     * @param list<PreparedLoad> $prepared
     */
    public function load(ReadExecutor $owner, array $records, array $prepared): void
    {
        if ($records === [] || $prepared === []) {
            return;
        }

        $primaryKey = $owner->mapping()->primaryKey;
        $keys = [];
        foreach ($records as $index => $record) {
            $keys[$index] = RowMapper::key($owner->mapper()->rowId($record->row[$primaryKey]));
        }

        foreach ($prepared as $load) {
            $relation = $load->relation;
            $groups = $relation instanceof BelongsToMany
                ? $this->linked($owner, $records, $keys, $load, $relation)
                : $this->direct($owner, $records, $keys, $load);
            $many = $relation->isMany();

            foreach ($records as $index => $record) {
                $group = $groups[$keys[$index]] ?? [];
                if (!$many && count($group) > 1) {
                    throw new UnexpectedValueException('A HasOne or BelongsTo relation matched more than one record.');
                }
                /** @var mixed $entity */
                $entity = ($relation->assign)($record->entity, $many ? $group : ($group[0] ?? null));
                if (!is_object($entity)) {
                    throw new UnexpectedValueException('A relation assign callback must return an object.');
                }
                $record->entity = $entity;
            }
        }
    }

    /**
     * Integer keys compare exactly, so targets are selected by key and matched in PHP: a target
     * shared by many owners is fetched and hydrated once. String keys follow the database
     * collation, so their owners are joined in SQL.
     *
     * @param ReadExecutor<object> $owner
     * @param list<Record<object>> $owners
     * @param array<int, string> $keys Owner keys aligned with $owners.
     * @return array<string, list<object>> Targets keyed by owner key.
     */
    private function direct(ReadExecutor $owner, array $owners, array $keys, PreparedLoad $load): array
    {
        $target = $load->executor;
        $ownerColumn = $load->relation->ownerKey($owner->mapping());
        $targetColumn = $load->relation->targetKey($target->mapping());
        if (KeyType::of($target->mapping()->columnType($targetColumn)) !== KeyType::Integer) {
            return $this->joined($owner, $owners, $load, $ownerColumn);
        }

        $values = [];
        $indexes = [];
        foreach ($owners as $index => $record) {
            if ($record->row[$ownerColumn] !== null) {
                $value = RowMapper::keyValue($record->row[$ownerColumn]);
                $values[RowMapper::key($value)] = $value;
                $indexes[RowMapper::key($value)][] = $index;
            }
        }

        $groups = [];
        foreach (array_chunk(array_values($values), $load->batchSize) as $chunk) {
            $related = $target->records(
                $target->selectWhereIn($load->query, $targetColumn, $chunk),
            );
            $this->load($target, $related, $load->children);
            foreach ($related as $record) {
                foreach ($indexes[RowMapper::key(RowMapper::keyValue($record->row[$targetColumn]))] ?? [] as $index) {
                    $groups[$keys[$index]][] = $record->entity;
                }
            }
        }

        return $groups;
    }

    /**
     * @param ReadExecutor<object> $owner
     * @param list<Record<object>> $owners
     * @return array<string, list<object>>
     */
    private function joined(ReadExecutor $owner, array $owners, PreparedLoad $load, string $ownerColumn): array
    {
        $target = $load->executor;
        $groups = [];
        foreach (array_chunk($this->ownerIds($owner, $owners, $ownerColumn), $load->batchSize) as $chunk) {
            [$related, $pairs] = $target->joinedRecords(
                $target->selectJoined($load->query, $load->relation, $owner, $chunk),
                $owner,
            );
            $this->load($target, $related, $load->children);
            foreach ($pairs as [$key, $record]) {
                $groups[$key][] = $record->entity;
            }
        }

        return $groups;
    }

    /**
     * @param ReadExecutor<object> $owner
     * @param list<Record<object>> $owners
     * @param array<int, string> $keys Owner keys aligned with $owners.
     * @param BelongsToMany<object, object> $relation
     * @return array<string, list<object>>
     */
    private function linked(
        ReadExecutor $owner,
        array $owners,
        array $keys,
        PreparedLoad $load,
        BelongsToMany $relation,
    ): array {
        $target = $load->executor;
        $groups = [];
        foreach (array_chunk($this->ownerIds($owner, $owners), $load->batchSize) as $chunk) {
            $ownersByTarget = [];
            foreach ($target->linkedKeys($relation, $owner, $chunk) as [$ownerKey, $targetKey]) {
                $ownersByTarget[$targetKey][$ownerKey] = true;
            }
            if ($ownersByTarget === []) {
                continue;
            }

            $related = $target->records($target->selectLinked($load->query, $relation, $owner, $chunk));
            $this->load($target, $related, $load->children);
            $primaryKey = $target->mapping()->primaryKey;
            foreach ($related as $record) {
                $targetKey = RowMapper::key($target->mapper()->rowId($record->row[$primaryKey]));
                foreach ($ownersByTarget[$targetKey] ?? [] as $ownerKey => $_) {
                    $groups[$ownerKey][] = $record->entity;
                }
            }
        }

        return $groups;
    }

    /**
     * Distinct owner primary keys, skipping owners whose required column is NULL.
     *
     * @param ReadExecutor<object> $owner
     * @param list<Record<object>> $owners
     * @return list<int|string>
     */
    private function ownerIds(ReadExecutor $owner, array $owners, ?string $requiredColumn = null): array
    {
        $primaryKey = $owner->mapping()->primaryKey;
        $ids = [];
        foreach ($owners as $record) {
            if ($requiredColumn === null || $record->row[$requiredColumn] !== null) {
                $id = $owner->mapper()->rowId($record->row[$primaryKey]);
                $ids[RowMapper::key($id)] = $id;
            }
        }

        return array_values($ids);
    }
}
