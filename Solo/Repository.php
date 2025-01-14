<?php

namespace Solo;

use Solo\Database;
use Solo\Repository\Interfaces\RepositoryInterface;
use Solo\Repository\RecordFactory;
use Solo\Repository\QueryBuilder;
use Solo\Repository\QueryParameters;

abstract class Repository implements RepositoryInterface
{
    protected string $table;
    protected string $alias;
    protected bool $distinct = false;
    protected ?array $orderBy = null;
    private bool $initialized = false;
    private QueryBuilder $queryBuilder;
    private QueryParameters $queryParams;

    public function __construct(
        protected readonly Database      $db,
        protected readonly RecordFactory $recordFactory
    )
    {
        if (!isset($this->table)) {
            throw new \LogicException('Table name must be defined in repository');
        }

        if ($this->initialized) {
            throw new \LogicException('Repository properties cannot be modified after initialization');
        }

        $this->alias ??= $this->table[0];
        $this->initialized = true;

        $this->queryBuilder = new QueryBuilder(
            $this->db,
            $this->table,
            $this->alias
        );

        $orderBy = $this->orderBy ? 'ORDER BY ' . implode(', ', array_map(
            fn($s) => "{$this->alias}.$s",
            $this->orderBy
        )) : '';

        $this->queryParams = new QueryParameters(
            select: $this->select(),
            joins: $this->joins(),
            orderBy: $orderBy,
            filters: $this->filters(),
            distinct: $this->distinct,
        );
    }

    protected function select(): string { return '*'; }
    protected function joins(): string { return ''; }
    protected function filters(): array { return []; }

    public function withDistinct(bool $distinct = true): self
    {
        $clone = clone $this;
        $clone->queryParams = $this->queryParams->withDistinct($distinct);
        return $clone;
    }

    public function withFilter(?array $filters): self
    {
        if ($filters === null || empty($filters)) {
            return clone $this;
        }

        $sqlParts = $this->queryBuilder->prepareFilters($filters, $this->filters());

        $clone = clone $this;
        $clone->queryParams = $this->queryParams
            ->withWhere($sqlParts['where'])
            ->withFilterJoins($sqlParts['joins'])
            ->withFilterSelect($sqlParts['select']);

        return $clone;
    }

    public function withOrderBy(?string ...$order): self
    {
        if (empty($order) || in_array(null, $order, true)) {
            return clone $this;
        }

        $orderBy = 'ORDER BY ' . implode(', ', array_map(
                fn($s) => "{$this->alias}.$s",
                $order
            ));

        $clone = clone $this;
        $clone->queryParams = $this->queryParams->withOrderBy($orderBy);
        return $clone;
    }

    public function withSorting(?string $order, ?string $direction = 'ASC'): self
    {
        if ($order === null) {
            return $this;
        }

        $direction = strtoupper($direction ?? 'ASC');
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'ASC';
        }

        return $this->withOrderBy("$order $direction");
    }

    public function withPage(?int $page, int $default = 1): self
    {
        if ($page === null) {
            $page = $default;
        }

        $clone = clone $this;
        $clone->queryParams = $this->queryParams->withPage($page);
        return $clone;
    }

    public function withLimit(?int $limit, int $default = 25): self
    {
        if ($limit === null) {
            $limit = $default;
        }

        $clone = clone $this;
        $clone->queryParams = $this->queryParams->withLimit($limit);
        return $clone;
    }

    public function withPrimaryKey(string $primaryKey): self
    {
        $clone = clone $this;
        $clone->queryParams = $this->queryParams->withPrimaryKey($primaryKey);
        return $clone;
    }

    public function create(array $data): int|false
    {
        $this->db->query("INSERT INTO ?t SET ?A", $this->table, $data);
        $id = $this->db->lastInsertId();
        return $id ? (int)$id : false;
    }

    public function update(int|array $id, array $data): int
    {
        $this->db->query(
            "UPDATE ?t SET ?A WHERE id IN(?a)",
            $this->table,
            $data,
            (array)$id
        );
        return $this->db->rowCount();
    }

    public function delete(int $id): int
    {
        $this->db->query(
            "DELETE FROM ?t WHERE id = ?i LIMIT 1",
            $this->table,
            $id
        );
        return $this->db->rowCount();
    }

    public function read(): array
    {
        $query = $this->queryBuilder->buildSelect($this->queryParams);
        $this->db->query($query);
        return $this->db->fetchAll($this->queryParams->getPrimaryKey());
    }

    public function readOne(): ?object
    {
        $clone = clone $this;
        $clone->queryParams = $this->queryParams
            ->withPage(1)
            ->withLimit(1);

        $query = $clone->queryBuilder->buildSelect($clone->queryParams);
        $clone->db->query($query);
        $result = $clone->db->fetchObject();

        return $result === false ? null : $result;
    }

    public function readAll(): array
    {
        $clone = clone $this;
        $clone->queryParams = $this->queryParams->clearLimit();
        return $clone->read();
    }

    public function count(): int
    {
        $query = $this->queryBuilder->buildCount($this->queryParams);
        $this->db->query($query);
        return $this->db->fetchObject('count');
    }

    public function exists(array $filters = []): bool
    {
        return $this->withFilter($filters)->count() > 0;
    }

    public function createEmptyRecord(): object
    {
        return $this->recordFactory->createEmpty($this->table);
    }

    public function beginTransaction(): bool
    {
        return $this->db->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->db->commit();
    }

    public function rollback(): bool
    {
        return $this->db->rollback();
    }
}