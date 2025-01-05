<?php

namespace Solo\Repository;

final class QueryParameters
{
    public function __construct(
        private readonly string $select = '*',
        private readonly string $joins = '',
        private readonly string $where = '',
        private readonly string $orderBy = '',
        private readonly string $limit = '',
        private readonly string $primaryKey = '',
        private readonly int    $page = 1,
        private readonly int    $itemLimit = 25,
        private readonly array  $filters = [],
        private readonly string $filterJoins = '',
        private readonly string $filterSelect = '',
        private readonly bool   $distinct = false
    )
    {
    }

    public function getSelect(): string { return $this->select; }
    public function getJoins(): string { return $this->joins; }
    public function getWhere(): string { return $this->where; }
    public function getOrderBy(): string { return $this->orderBy; }
    public function getLimit(): string { return $this->limit; }
    public function getPrimaryKey(): string { return $this->primaryKey; }
    public function getFilterJoins(): string { return $this->filterJoins; }
    public function getFilterSelect(): string { return $this->filterSelect; }
    public function isDistinct(): bool { return $this->distinct; }

    public function withSelect(string $select): self
    {
        return new self(
            select: $select,
            joins: $this->joins,
            where: $this->where,
            orderBy: $this->orderBy,
            limit: $this->limit,
            primaryKey: $this->primaryKey,
            page: $this->page,
            itemLimit: $this->itemLimit,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect,
            distinct: $this->distinct
        );
    }

    public function withDistinct(bool $distinct = true): self
    {
        return new self(
            select: $this->select,
            joins: $this->joins,
            where: $this->where,
            orderBy: $this->orderBy,
            limit: $this->limit,
            primaryKey: $this->primaryKey,
            page: $this->page,
            itemLimit: $this->itemLimit,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect,
            distinct: $distinct
        );
    }

    public function withJoins(string $joins): self
    {
        return new self(
            select: $this->select,
            joins: $joins,
            where: $this->where,
            orderBy: $this->orderBy,
            limit: $this->limit,
            primaryKey: $this->primaryKey,
            page: $this->page,
            itemLimit: $this->itemLimit,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect,
            distinct: $this->distinct
        );
    }

    public function withWhere(string $where): self
    {
        return new self(
            select: $this->select,
            joins: $this->joins,
            where: $where,
            orderBy: $this->orderBy,
            limit: $this->limit,
            primaryKey: $this->primaryKey,
            page: $this->page,
            itemLimit: $this->itemLimit,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect,
            distinct: $this->distinct
        );
    }

    public function withOrderBy(string $orderBy): self
    {
        return new self(
            select: $this->select,
            joins: $this->joins,
            where: $this->where,
            orderBy: $orderBy,
            limit: $this->limit,
            primaryKey: $this->primaryKey,
            page: $this->page,
            itemLimit: $this->itemLimit,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect,
            distinct: $this->distinct
        );
    }

    public function withPage(int $page): self
    {
        $newPage = max(1, $page);
        $limit = "LIMIT " . (($newPage - 1) * $this->itemLimit) . ", $this->itemLimit";

        return new self(
            select: $this->select,
            joins: $this->joins,
            where: $this->where,
            orderBy: $this->orderBy,
            limit: $limit,
            primaryKey: $this->primaryKey,
            page: $newPage,
            itemLimit: $this->itemLimit,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect,
            distinct: $this->distinct
        );
    }

    public function withLimit(int $limit): self
    {
        $newLimit = max(1, $limit);
        $limitClause = "LIMIT " . (($this->page - 1) * $newLimit) . ", $newLimit";

        return new self(
            select: $this->select,
            joins: $this->joins,
            where: $this->where,
            orderBy: $this->orderBy,
            limit: $limitClause,
            primaryKey: $this->primaryKey,
            page: $this->page,
            itemLimit: $newLimit,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect,
            distinct: $this->distinct
        );
    }

    public function withPrimaryKey(string $primaryKey): self
    {
        return new self(
            select: $this->select,
            joins: $this->joins,
            where: $this->where,
            orderBy: $this->orderBy,
            limit: $this->limit,
            primaryKey: $primaryKey,
            page: $this->page,
            itemLimit: $this->itemLimit,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect,
            distinct: $this->distinct
        );
    }

    public function withFilterJoins(string $joins): self
    {
        return new self(
            select: $this->select,
            joins: $this->joins,
            where: $this->where,
            orderBy: $this->orderBy,
            limit: $this->limit,
            primaryKey: $this->primaryKey,
            page: $this->page,
            itemLimit: $this->itemLimit,
            filters: $this->filters,
            filterJoins: $joins,
            filterSelect: $this->filterSelect,
            distinct: $this->distinct
        );
    }

    public function withFilterSelect(string $select): self
    {
        return new self(
            select: $this->select,
            joins: $this->joins,
            where: $this->where,
            orderBy: $this->orderBy,
            limit: $this->limit,
            primaryKey: $this->primaryKey,
            page: $this->page,
            itemLimit: $this->itemLimit,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $select,
            distinct: $this->distinct
        );
    }

    public function clearLimit(): self
    {
        return new self(
            select: $this->select,
            joins: $this->joins,
            where: $this->where,
            orderBy: $this->orderBy,
            limit: '',
            primaryKey: $this->primaryKey,
            page: $this->page,
            itemLimit: $this->itemLimit,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect,
            distinct: $this->distinct
        );
    }
}