<?php

namespace Solo\Repository;

use Solo\Database;
use Closure;

final class QueryBuilder
{
    public function __construct(
        private readonly Database $db,
        private readonly string   $table,
        private readonly string   $alias
    )
    {
    }

    public function buildSelect(QueryParameters $params): string
    {
        $baseSelect = $params->getSelect();
        $filterSelect = $params->getFilterSelect();

        $select = $baseSelect === '*'
            ? "{$this->alias}.*, " . ($filterSelect ?: '')
            : $baseSelect . ($filterSelect ? ", $filterSelect" : '');

        $select = rtrim($select, ', ');
        $distinct = $params->isDistinct() ? 'DISTINCT ' : '';

        return trim("
            SELECT {$distinct}{$select}
            FROM {$this->table} AS {$this->alias}
            {$params->getJoins()} 
            {$params->getFilterJoins()}
            WHERE 1 {$params->getWhere()}
            {$params->getOrderBy()}
            {$params->getLimit()}
        ");
    }

    public function buildCount(QueryParameters $params): string
    {
        return trim("
            SELECT COUNT(*) AS count
            FROM {$this->table} AS {$this->alias}
            {$params->getJoins()}
            {$params->getFilterJoins()}
            WHERE 1 {$params->getWhere()}
        ");
    }

    public function prepareFilters(array $filters, array $filterDefinitions): array
    {
        $where = '';
        $joins = [];
        $select = [];

        foreach ($filters as $field => $value) {
           if ($value === null || !isset($filterDefinitions[$field])) {
                continue;
            }

            $config = $filterDefinitions[$field];
            $condition = $config instanceof FilterConfig ? $config->where : $config;

            if ($config instanceof FilterConfig && $config->search) {
                $where .= $this->buildSearchFilter($value, $config->search);
            } elseif ($condition instanceof Closure) {
                $where .= $condition($value);
            } elseif (is_string($condition)) {
                if (str_contains($condition, '?a')) {
                    $value = (array)$value;
                }
                $where .= $this->db->prepare(" $condition", $value);
            }

        if ($config instanceof FilterConfig) {
            if ($config->joins) {
                $joins[] = $config->joins;
            }
            if ($config->select) {
                $select[] = $config->select;
            }
        }
        }

        return [
            'where' => $where,
            'joins' => implode(' ', array_unique($joins)),
            'select' => implode(', ', array_unique($select))
        ];
    }

    public function buildSearchFilter(string $value, array $search): string
    {
        $field = $search[0];

        if (str_contains($value, ':')) {
            [$f, $value] = explode(':', $value, 2);
            if (in_array($f, $search, true)) {
                $field = $f;
            }
        }

        $filter = '';
        foreach (explode(' ', $value) as $kw) {
            if ($kw = trim($kw)) {
                $filter .= $this->db->prepare("AND {$this->alias}.$field LIKE ?l", $kw);
            }
        }
        return $filter;
    }
}