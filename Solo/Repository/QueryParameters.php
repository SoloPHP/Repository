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
        private readonly string $page = '1',
        private readonly string $perPage = '25',
        private readonly array  $filters = []
    )
    {
    }

    public function getSelect(): string
    {
        return $this->select;
    }

    public function getJoins(): string
    {
        return $this->joins;
    }

    public function getWhere(): string
    {
        return $this->where;
    }

    public function getOrderBy(): string
    {
        return $this->orderBy;
    }

    public function getLimit(): string
    {
        return $this->limit;
    }

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    public function getPage(): string
    {
        return $this->page;
    }

    public function getPerPage(): string
    {
        return $this->perPage;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function withSelect(string $select): self
    {
        return new self(
            $select,
            $this->joins,
            $this->where,
            $this->orderBy,
            $this->limit,
            $this->primaryKey,
            $this->page,
            $this->perPage,
            $this->filters
        );
    }

    public function withJoins(string $joins): self
    {
        return new self(
            $this->select,
            $joins,
            $this->where,
            $this->orderBy,
            $this->limit,
            $this->primaryKey,
            $this->page,
            $this->perPage,
            $this->filters
        );
    }

    public function withWhere(string $where): self
    {
        return new self(
            $this->select,
            $this->joins,
            $where,
            $this->orderBy,
            $this->limit,
            $this->primaryKey,
            $this->page,
            $this->perPage,
            $this->filters
        );
    }

    public function withOrderBy(string $orderBy): self
    {
        return new self(
            $this->select,
            $this->joins,
            $this->where,
            $orderBy,
            $this->limit,
            $this->primaryKey,
            $this->page,
            $this->perPage,
            $this->filters
        );
    }

    public function withLimit(string $limit): self
    {
        return new self(
            $this->select,
            $this->joins,
            $this->where,
            $this->orderBy,
            $limit,
            $this->primaryKey,
            $this->page,
            $this->perPage,
            $this->filters
        );
    }

    public function withPrimaryKey(string $primaryKey): self
    {
        return new self(
            $this->select,
            $this->joins,
            $this->where,
            $this->orderBy,
            $this->limit,
            $primaryKey,
            $this->page,
            $this->perPage,
            $this->filters
        );
    }

    public function withPage(int $page): self
    {
        $newPage = max(1, $page);
        $limit = "LIMIT " . (((int)$this->page - 1) * $newPage) . ", $newPage";

        return new self(
            $this->select,
            $this->joins,
            $this->where,
            $this->orderBy,
            $limit,
            $this->primaryKey,
            $newPage,
            $this->perPage,
            $this->filters
        );
    }

    public function withPerPage(int $perPage): self
    {
        $newPerPage = max(1, $perPage);
        $limit = "LIMIT " . (((int)$this->page - 1) * $newPerPage) . ", $newPerPage";

        return new self(
            $this->select,
            $this->joins,
            $this->where,
            $this->orderBy,
            $limit,
            $this->primaryKey,
            $this->page,
            $newPerPage,
            $this->filters
        );
    }

    public function withFilters(array $filters): self
    {
        return new self(
            $this->select,
            $this->joins,
            $this->where,
            $this->orderBy,
            $this->limit,
            $this->primaryKey,
            $this->page,
            $this->perPage,
            $filters
        );
    }

    public function clearLimit(): self
    {
        return new self(
            $this->select,
            $this->joins,
            $this->where,
            $this->orderBy,
            '',
            $this->primaryKey,
            $this->page,
            $this->perPage,
            $this->filters
        );
    }

}