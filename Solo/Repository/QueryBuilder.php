<?php

namespace Solo\Repository;

use Solo\Database;
use Solo\Repository\Interfaces\QueryBuilderInterface;

abstract class QueryBuilder implements QueryBuilderInterface
{
    private string $select = '*';

    private string $joins = '';
    private string $where = '';
    private string $orderBy = '';
    private string $limit = '';
    protected string $primaryKey = '';
    private int $page = 1;
    private int $perPage = 100;
    private array $filters = [];

    public function __construct(
        private Database $db
    )
    {
    }

    protected function setSelect(string $select): self
    {
        $this->select = $select;
        return $this;
    }

    protected function setJoins(string $joins): self
    {
        $this->joins = $joins;
        return $this;
    }

    protected function setFilters(array $filters): self
    {
        $this->filters = $filters;
        return $this;
    }


    public function filter(?array $filters): self
    {
        if ($filters === null || empty($filters)) {
            return $this;
        }

        $cleanFilters = array_filter($filters, fn($value) => $value !== null);
        $sqlParts = $this->prepareFilters($cleanFilters);
        $this->where = $sqlParts['where'];
        $this->joins = $sqlParts['joins'];

        return $this;
    }

    public function orderBy(?string ...$order): self
    {
        if (empty($order) || in_array(null, $order, true)) {
            return $this;
        }

        $this->orderBy = 'ORDER BY ' . implode(', ', array_map(fn($s) => "$this->alias.$s", $order));
        return $this;
    }

    public function page(?int $page): self
    {
        if ($page !== null) {
            $this->page = max(1, $page);
            $this->limit = "LIMIT " . (($this->page - 1) * $this->perPage) . ", $this->perPage";
        }
        return $this;
    }

    public function perPage(?int $perPage): self
    {
        if ($perPage !== null) {
            $this->perPage = max(1, $perPage);
            $this->limit = "LIMIT " . (($this->page - 1) * $this->perPage) . ", $this->perPage";
        }
        return $this;
    }

    public function limit(int $page, int $perPage): self
    {
        return $this->page($page)->perPage($perPage);
    }

    public function primaryKey(string $primaryKey): self
    {
        $this->primaryKey = $primaryKey;
        return $this;
    }

    protected function clearLimit(): self
    {
        $this->limit = '';
        return $this;
    }

    protected function buildQuery(): string
    {
        return trim("
            SELECT $this->select
            $this->from
            $this->joins 
            WHERE 1 $this->where
            $this->orderBy
            $this->limit
        ");
    }

    protected function buildCountQuery(): string
    {
        return trim("
            SELECT COUNT(*) AS count
            $this->from
            $this->joins
            WHERE 1 $this->where
        ");
    }

    private function buildFilter(string $field, $value): string
    {
        if (!isset($this->filters[$field])) {
            return '';
        }

        $filter = $this->filters[$field];

        if (is_callable($filter)) {
            return $filter($value);
        }

        if (is_string($filter)) {
            if (str_contains($filter, '?a')) {
                $value = (array)$value;
            }
            return $this->db->prepare(" $filter", $value);
        }

        return '';
    }

    private function prepareFilters(array $filters): array
    {
        $where = '';
        foreach ($filters as $field => $value) {
            if ($value !== null) {
                $where .= $this->buildFilter($field, $value);
            }
        }
        return ['where' => $where, 'joins' => $this->joins];
    }
}