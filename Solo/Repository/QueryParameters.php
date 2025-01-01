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
        private readonly int    $perPage = 25,
        private readonly array  $filters = [],
        private readonly string $filterJoins = '',
        private readonly string $filterSelect = ''
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
            perPage: $this->perPage,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect
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
            perPage: $this->perPage,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect
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
            perPage: $this->perPage,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect
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
            perPage: $this->perPage,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect
        );
    }

    public function withPage(?string $page): self
    {
        $newPage = $page === null ? 1 : max(1, (int)$page);
        $limit = "LIMIT " . (($newPage - 1) * $this->perPage) . ", $this->perPage";

        return new self(
            select: $this->select,
            joins: $this->joins,
            where: $this->where,
            orderBy: $this->orderBy,
            limit: $limit,
            primaryKey: $this->primaryKey,
            page: $newPage,
            perPage: $this->perPage,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect
        );
    }

    public function withPerPage(?string $perPage): self
    {
        $newPerPage = $perPage === null ? 25 : max(1, (int)$perPage);
        $limit = "LIMIT " . (($this->page - 1) * $newPerPage) . ", $newPerPage";

        return new self(
            select: $this->select,
            joins: $this->joins,
            where: $this->where,
            orderBy: $this->orderBy,
            limit: $limit,
            primaryKey: $this->primaryKey,
            page: $this->page,
            perPage: $newPerPage,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect
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
            perPage: $this->perPage,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect
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
            perPage: $this->perPage,
            filters: $this->filters,
            filterJoins: $joins,
            filterSelect: $this->filterSelect
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
            perPage: $this->perPage,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $select
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
            perPage: $this->perPage,
            filters: $this->filters,
            filterJoins: $this->filterJoins,
            filterSelect: $this->filterSelect
        );
    }
}