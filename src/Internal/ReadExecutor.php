<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use Solo\Repository\Filter;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsTo;
use Solo\Repository\Relations\BelongsToMany;
use Solo\Repository\Relations\HasMany;
use Solo\Repository\Relations\HasOne;
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
    private ?Locale $locale;

    /** @param Mapping<T> $mapping */
    public function __construct(
        private Connection $connection,
        private Mapping $mapping,
        private Visibility $visibility = Visibility::Active,
        ?Locale $locale = null,
    ) {
        if ($visibility === Visibility::Deleted && $mapping->softDeleteColumn === null) {
            throw new InvalidArgumentException('Deleted visibility requires a soft-delete column.');
        }

        $this->locale = $locale ?? ($mapping->translations === null
            ? null
            : new Locale($mapping->translations->defaultLocale));
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
        foreach ($orderBy as $column => $direction) {
            if (!is_string($column) || !is_string($direction)) {
                throw new InvalidArgumentException('Order must map column names to ASC or DESC.');
            }
            $direction = strtoupper($direction);
            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                throw new InvalidArgumentException('Order direction must be ASC or DESC.');
            }
            $query->addOrderBy($fields->expression($column), $direction);
        }

        $this->applyCriteria($query, $fields, $criteria);

        return $query->setMaxResults($limit)->setFirstResult($offset);
    }

    /** @param array<string, mixed>|Filter $criteria */
    public function baseQuery(array|Filter $criteria = []): QueryBuilder
    {
        [$query, $fields] = $this->createQuery();
        $this->applyCriteria($query, $fields, $criteria);

        return $query;
    }

    /**
     * Join owners to keep key matching in SQL, including string-key collation rules.
     *
     * @param list<int|string> $ownerIds
     * @param array<string, mixed>|Filter $criteria
     * @param array<string, string> $orderBy
     */
    public function selectRelated(
        Relation $via,
        Mapping $owner,
        array $ownerIds,
        array|Filter $criteria = [],
        array $orderBy = [],
    ): QueryBuilder {
        $query = $this->select(array_keys($this->mapping->readColumns()), $criteria, $orderBy);
        $ownerKey = $this->qualified('br_owner', $owner->primaryKey);
        if ($via instanceof BelongsTo) {
            $condition = $this->qualified('br_base', $this->mapping->primaryKey)
                . ' = ' . $this->qualified('br_owner', $via->foreignKey);
        } elseif ($via instanceof HasOne || $via instanceof HasMany) {
            $condition = $ownerKey . ' = ' . $this->qualified('br_base', $via->foreignKey);
        } else {
            throw new InvalidArgumentException('A direct load requires BelongsTo, HasOne or HasMany.');
        }

        $query->innerJoin('br_base', $this->connection->quoteIdentifier($owner->table), 'br_owner', $condition)
            ->addSelect($ownerKey . ' AS ' . $this->connection->quoteIdentifier($this->ownerColumn()))
            ->andWhere($this->ownerPredicate($query, $ownerKey, $owner, $ownerIds));

        return $query;
    }

    /**
     * Keep each owner's entire collection in one ordered SQL result, even with large fan-out.
     *
     * @param list<int|string> $ownerIds
     * @param array<string, mixed>|Filter $criteria
     * @param array<string, string> $orderBy
     */
    public function selectLinked(
        BelongsToMany $via,
        Mapping $owner,
        array $ownerIds,
        array|Filter $criteria = [],
        array $orderBy = [],
    ): QueryBuilder {
        $query = $this->select(array_keys($this->mapping->readColumns()), $criteria, $orderBy);
        $quote = $this->connection->quoteIdentifier(...);
        $ownerKey = $this->qualified('br_link_owner', $owner->primaryKey);
        $query->andWhere('EXISTS (SELECT 1 FROM ' . $quote($via->pivotTable) . ' br_link'
            . ' INNER JOIN ' . $quote($owner->table) . ' br_link_owner ON ' . $ownerKey
            . ' = ' . $this->qualified('br_link', $via->foreignPivotKey)
            . ' WHERE ' . $this->qualified('br_base', $this->mapping->primaryKey)
            . ' = ' . $this->qualified('br_link', $via->relatedPivotKey)
            . ' AND ' . $this->ownerPredicate($query, $ownerKey, $owner, $ownerIds) . ')');

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

    /** @return list<Record<T>> */
    public function records(QueryBuilder $query, ?Mapping $owner = null): array
    {
        $mapper = new RowMapper($this->mapping, $this->connection->getDatabasePlatform());
        $ownerMapper = $owner === null ? null : new RowMapper($owner, $this->connection->getDatabasePlatform());
        $ownerColumn = $this->ownerColumn();
        $records = [];
        foreach ($this->fetchRows($query) as $row) {
            $ownerId = null;
            if ($ownerMapper !== null) {
                $ownerId = $ownerMapper->id($row[$ownerColumn]);
                unset($row[$ownerColumn]);
            }
            $row = $mapper->convert($row);
            $records[] = new Record($row, $mapper->hydrateConverted($row), $ownerId);
        }

        return $records;
    }

    /** @param list<int|string> $ownerIds */
    private function ownerPredicate(QueryBuilder $query, string $field, Mapping $owner, array $ownerIds): string
    {
        if (count($query->getParameters()) + count($ownerIds) > PredicateCompiler::MAX_PARAMETERS) {
            throw new InvalidArgumentException('The relation query exceeds the parameter limit.');
        }
        if ($ownerIds === []) {
            return '1 = 0';
        }
        $type = $owner->columnType($owner->primaryKey);
        $parameters = [];
        foreach ($ownerIds as $id) {
            $parameters[] = $query->createNamedParameter($id, $type);
        }

        return $field . ' IN (' . implode(', ', $parameters) . ')';
    }

    private function ownerColumn(): string
    {
        $column = 'br_owner_id';
        $fields = $this->mapping->readColumns();
        while (array_key_exists($column, $fields)) {
            $column .= '_';
        }

        return $column;
    }

    private function qualified(string $alias, string $column): string
    {
        return $this->connection->quoteIdentifier($alias) . '.' . $this->connection->quoteIdentifier($column);
    }

    /** @return array{QueryBuilder, FieldResolver} */
    private function createQuery(): array
    {
        $query = $this->connection->createQueryBuilder();
        $fields = new FieldResolver($this->connection, $this->mapping, $query, $this->locale, alias: 'br_base');
        $query->from($this->connection->quoteIdentifier($this->mapping->table), $fields->baseAlias());

        return [$query, $fields];
    }

    /** @param array<string, mixed>|Filter $criteria */
    private function applyCriteria(QueryBuilder $query, FieldResolver $fields, array|Filter $criteria): void
    {
        $predicate = (new PredicateCompiler($this->connection, $this->mapping, $query, fields: $fields))
            ->compile($criteria);
        $query->where(is_bool($predicate) ? ($predicate ? '1 = 1' : '1 = 0') : $predicate);

        if ($this->mapping->softDeleteColumn !== null && $this->visibility !== Visibility::All) {
            $column = $fields->expression($this->mapping->softDeleteColumn);
            $query->andWhere($column . ($this->visibility === Visibility::Deleted ? ' IS NOT NULL' : ' IS NULL'));
        }
    }
}
