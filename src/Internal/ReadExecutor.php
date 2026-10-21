<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Closure;
use Doctrine\DBAL\Query\QueryBuilder;
use Generator;
use InvalidArgumentException;
use Solo\Repository\Filter;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsToMany;
use Solo\Repository\Relations\Relation;
use Solo\Repository\Visibility;

/**
 * Builds fresh read statements shared by root reads and batched relation loads.
 *
 * @internal
 * @template T of object
 */
final readonly class ReadExecutor
{
    private const string ALIAS = 'br_base';

    private ?Locale $locale;

    /** @var RowMapper<T> */
    private RowMapper $mapper;

    /** @param Mapping<T> $mapping */
    public function __construct(
        private Database $db,
        private Mapping $mapping,
        private Visibility $visibility = Visibility::Active,
        ?Locale $locale = null,
    ) {
        SoftDelete::assertSupported($mapping, $visibility);
        $this->locale = Locale::effective($locale, $mapping);
        $this->mapper = new RowMapper($mapping, $db);
    }

    /** @return Mapping<T> */
    public function mapping(): Mapping
    {
        return $this->mapping;
    }

    /** @return RowMapper<T> */
    public function mapper(): RowMapper
    {
        return $this->mapper;
    }

    public function locale(): ?Locale
    {
        return $this->locale;
    }

    /**
     * @param array<array-key, mixed> $columns
     * @param array<string, mixed>|Filter $criteria
     * @param array<array-key, mixed> $orderBy
     */
    public function select(
        array $columns,
        array|Filter $criteria = [],
        array $orderBy = [],
        ?int $limit = null,
        int $offset = 0,
    ): QueryBuilder {
        if ($columns === [] || !array_is_list($columns)) {
            throw new InvalidArgumentException('Select columns must be a non-empty list.');
        }
        if (($limit !== null && $limit < 0) || $offset < 0) {
            throw new InvalidArgumentException('Limit and offset must not be negative.');
        }

        [$query, $fields] = $this->createQuery();
        $projection = [];
        foreach ($columns as $column) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Select columns must be strings.');
            }
            $projection[] = $fields->projection($column);
        }
        $query->select(...$projection);
        foreach (OrderBy::normalize($orderBy) as $field => $direction) {
            $query->addOrderBy($fields->expression($field), $direction);
        }

        $this->applyCriteria($query, $fields, $criteria);

        return $query->setMaxResults($limit)->setFirstResult($offset);
    }

    /**
     * Every read field, as model reads and relation loads select them.
     *
     * @param array<string, mixed>|Filter $criteria
     * @param array<array-key, mixed> $orderBy
     */
    public function selectAll(
        array|Filter $criteria = [],
        array $orderBy = [],
        ?int $limit = null,
        int $offset = 0,
    ): QueryBuilder {
        return $this->select(array_keys($this->mapping->readColumns()), $criteria, $orderBy, $limit, $offset);
    }

    /**
     * The visible rows that match the criteria, under the caller's alias, with nothing selected.
     *
     * @param array<string, mixed>|Filter $criteria
     */
    public function filtered(string $alias, array|Filter $criteria): QueryBuilder
    {
        [$query, $fields] = $this->createQuery($alias);
        $this->applyCriteria($query, $fields, $criteria);

        return $query;
    }

    /**
     * One value selected over the visible rows that match the criteria.
     *
     * @param Closure(FieldResolver): string $select
     * @param array<string, mixed>|Filter $criteria
     */
    public function scalar(Closure $select, array|Filter $criteria, ?int $limit = null): mixed
    {
        [$query, $fields] = $this->createQuery();
        $query->select($select($fields));
        $this->applyCriteria($query, $fields, $criteria);
        $result = $query->setMaxResults($limit)->executeQuery();
        try {
            return $result->fetchOne();
        } finally {
            $result->free();
        }
    }

    /**
     * Targets whose key column holds one of the integer keys; the loader matches them in PHP.
     *
     * @param QueryBuilder $prepared A selectAll() read of this target; it is cloned, not changed.
     * @param non-empty-list<int|string> $keys
     */
    public function selectWhereIn(QueryBuilder $prepared, string $column, array $keys): QueryBuilder
    {
        $query = clone $prepared;

        return $query->andWhere($this->db->inList(
            $query,
            $this->db->qualified(self::ALIAS, $column),
            $this->mapping->columnType($column),
            $keys,
        ));
    }

    /**
     * Joins owners so that string keys match under the database collation; every row also selects
     * the canonical key of its owner. See joinedRecords().
     *
     * @param QueryBuilder $prepared A selectAll() read of this target; it is cloned, not changed.
     * @param ReadExecutor<object> $owner
     * @param non-empty-list<int|string> $ownerIds
     */
    public function selectJoined(
        QueryBuilder $prepared,
        Relation $relation,
        ReadExecutor $owner,
        array $ownerIds,
    ): QueryBuilder {
        $query = clone $prepared;
        $ownerMapping = $owner->mapping();
        $ownerPrimaryKey = $ownerMapping->requirePrimaryKey('Matching relation owners in SQL');
        $ownerKey = $this->db->qualified('br_owner', $ownerPrimaryKey);
        $query->innerJoin(
            self::ALIAS,
            $this->db->quote($ownerMapping->table),
            'br_owner',
            $relation->keyCondition(
                $this->db->qualified('br_owner', $relation->ownerKey($ownerMapping)),
                $this->db->qualified(self::ALIAS, $relation->targetKey($this->mapping)),
            ),
        )
            ->addSelect($ownerKey . ' AS ' . $this->db->quote($this->ownerColumn()))
            ->andWhere($this->db->inList(
                $query,
                $ownerKey,
                $ownerMapping->columnType($ownerPrimaryKey),
                $ownerIds,
            ));

        return $query;
    }

    /**
     * Owner and target keys of the pivot rows of these owners, canonical through both tables.
     *
     * @param BelongsToMany<object, object> $via
     * @param ReadExecutor<object> $owner
     * @param non-empty-list<int|string> $ownerIds
     * @return list<array{string, string}>
     */
    public function linkedKeys(BelongsToMany $via, ReadExecutor $owner, array $ownerIds): array
    {
        $ownerMapping = $owner->mapping();
        $ownerPrimaryKey = $via->ownerKey($ownerMapping);
        $ownerKey = $this->db->qualified('br_owner_table', $ownerPrimaryKey);
        $targetKey = $this->db->qualified('br_target_table', $via->targetKey($this->mapping));
        $query = $this->db->connection->createQueryBuilder()
            ->select(
                $ownerKey . ' AS ' . $this->db->quote('br_owner'),
                $targetKey . ' AS ' . $this->db->quote('br_related'),
            )
            ->from($this->db->quote($via->pivotTable), 'br_pivot')
            ->innerJoin(
                'br_pivot',
                $this->db->quote($ownerMapping->table),
                'br_owner_table',
                $ownerKey . ' = ' . $this->db->qualified('br_pivot', $via->foreignPivotKey),
            )
            ->innerJoin(
                'br_pivot',
                $this->db->quote($this->mapping->table),
                'br_target_table',
                $targetKey . ' = ' . $this->db->qualified('br_pivot', $via->relatedPivotKey),
            );
        $query->where($this->db->inList(
            $query,
            $ownerKey,
            $ownerMapping->columnType($ownerPrimaryKey),
            $ownerIds,
        ));

        $keys = [];
        foreach ($this->fetchRows($query) as $row) {
            $keys[] = [
                RowMapper::key($owner->mapper()->rowId($row['br_owner'])),
                RowMapper::key($this->mapper->rowId($row['br_related'])),
            ];
        }

        return $keys;
    }

    /**
     * Keeps each owner's entire collection in one ordered SQL result, even with large fan-out.
     *
     * @param QueryBuilder $prepared A selectAll() read of this target; it is cloned, not changed.
     * @param BelongsToMany<object, object> $via
     * @param ReadExecutor<object> $owner
     * @param non-empty-list<int|string> $ownerIds
     */
    public function selectLinked(
        QueryBuilder $prepared,
        BelongsToMany $via,
        ReadExecutor $owner,
        array $ownerIds,
    ): QueryBuilder {
        $query = clone $prepared;
        $ownerMapping = $owner->mapping();
        $ownerPrimaryKey = $via->ownerKey($ownerMapping);
        $ownerKey = $this->db->qualified('br_link_owner', $ownerPrimaryKey);
        $query->andWhere('EXISTS (SELECT 1 FROM ' . $this->db->quote($via->pivotTable)
            . ' ' . $this->db->quote('br_link')
            . ' INNER JOIN ' . $this->db->quote($ownerMapping->table) . ' ' . $this->db->quote('br_link_owner')
            . ' ON ' . $ownerKey . ' = ' . $this->db->qualified('br_link', $via->foreignPivotKey)
            . ' WHERE ' . $this->db->qualified(self::ALIAS, $via->targetKey($this->mapping))
            . ' = ' . $this->db->qualified('br_link', $via->relatedPivotKey)
            . ' AND ' . $this->db->inList(
                $query,
                $ownerKey,
                $ownerMapping->columnType($ownerPrimaryKey),
                $ownerIds,
            ) . ')');

        return $query;
    }

    /** @return list<array<string, mixed>> */
    public function fetchRows(QueryBuilder $query): array
    {
        $result = $query->executeQuery();
        try {
            return $result->fetchAllAssociative();
        } finally {
            $result->free();
        }
    }

    /** @return list<array<string, mixed>> */
    public function convertedRows(QueryBuilder $query): array
    {
        $rows = [];
        foreach ($this->releasedRows($query) as $row) {
            $rows[] = $this->mapper->convert($row);
        }

        return $rows;
    }

    /**
     * Converted rows and their models, as relation loading needs them.
     *
     * @return list<Record<T>>
     */
    public function records(QueryBuilder $query): array
    {
        $records = [];
        foreach ($this->releasedRows($query) as $row) {
            $row = $this->mapper->convert($row);
            $records[] = new Record($row, $this->mapper->hydrate($row));
        }

        return $records;
    }

    /**
     * Models of a read without relation loads.
     *
     * @return list<T>
     */
    public function entities(QueryBuilder $query): array
    {
        $entities = [];
        foreach ($this->releasedRows($query) as $row) {
            $entities[] = $this->mapper->hydrate($this->mapper->convert($row));
        }

        return $entities;
    }

    /**
     * Hydrates each distinct row of a selectJoined() result once and pairs it with every owner.
     *
     * @param ReadExecutor<object> $owner
     * @return array{list<Record<T>>, list<array{string, Record<T>}>} records and [owner key, record] pairs
     */
    public function joinedRecords(QueryBuilder $query, ReadExecutor $owner): array
    {
        $ownerColumn = $this->ownerColumn();
        $primaryKey = $this->mapping->primaryKey;
        $records = [];
        $pairs = [];
        foreach ($this->releasedRows($query) as $index => $row) {
            $ownerKey = RowMapper::key($owner->mapper()->rowId($row[$ownerColumn]));
            // A target without a primary key is a HasOne or HasMany row, which joins one owner only.
            $key = $primaryKey === null ? $index : RowMapper::key($this->mapper->rowId($row[$primaryKey]));
            if (!isset($records[$key])) {
                unset($row[$ownerColumn]);
                $row = $this->mapper->convert($row);
                $records[$key] = new Record($row, $this->mapper->hydrate($row));
            }
            $pairs[] = [$ownerKey, $records[$key]];
        }

        return [array_values($records), $pairs];
    }

    /**
     * The fetched rows one at a time. The whole result is fetched and freed before the first row,
     * and each row is released once the caller moves on, so raw rows do not pile up next to
     * what is built from them.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function releasedRows(QueryBuilder $query): Generator
    {
        $rows = $this->fetchRows($query);
        for ($index = 0, $count = count($rows); $index < $count; ++$index) {
            $row = $rows[$index];
            unset($rows[$index]);
            yield $row;
        }
    }

    /** The owner key column of a joined result, named apart from every mapped field. */
    private function ownerColumn(): string
    {
        $column = 'br_owner_id';
        $fields = $this->mapping->readColumns();
        while (array_key_exists($column, $fields)) {
            $column .= '_';
        }

        return $column;
    }

    /** @return array{QueryBuilder, FieldResolver} */
    private function createQuery(string $alias = self::ALIAS): array
    {
        $query = $this->db->connection->createQueryBuilder();
        $fields = new FieldResolver($this->db, $this->mapping, $query, $alias, $this->locale);
        $query->from($this->db->quote($this->mapping->table), $alias);

        return [$query, $fields];
    }

    /** @param array<string, mixed>|Filter $criteria */
    private function applyCriteria(QueryBuilder $query, FieldResolver $fields, array|Filter $criteria): void
    {
        $predicate = (new PredicateCompiler($this->db, $this->mapping, $query, $fields))->compile($criteria);
        $query->where(is_bool($predicate) ? ($predicate ? '1 = 1' : '1 = 0') : $predicate);

        $visibility = SoftDelete::condition($this->mapping, $this->visibility, $fields->expression(...));
        if ($visibility !== null) {
            $query->andWhere($visibility);
        }
    }
}
