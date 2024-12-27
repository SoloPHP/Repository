<?php

namespace Solo\Repository;

use Solo\Database;

final class QueryBuilder
{
    public function __construct(
        private readonly Database $db,
        private readonly string $table,
        private readonly string $alias
    ) {}

    public function buildSelect(QueryParameters $params): string
    {
        return trim("
            SELECT {$params->getSelect()}
            FROM {$this->table} AS {$this->alias}
            {$params->getJoins()} 
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
            WHERE 1 {$params->getWhere()}
        ");
    }

    public function prepareFilters(array $filters, array $filterDefinitions): array
    {
        $where = '';
        $cleanFilters = array_filter($filters, fn($value) => $value !== null);

        foreach ($cleanFilters as $field => $value) {
            if (!isset($filterDefinitions[$field])) {
                continue;
        }

            $filter = $filterDefinitions[$field];

            if (is_callable($filter)) {
                $where .= $filter($value);
            } elseif (is_string($filter)) {
                if (str_contains($filter, '?a')) {
                    $value = (array)$value;
                }
                $where .= $this->db->prepare(" $filter", $value);
            }
        }

        return ['where' => $where];
    }
}