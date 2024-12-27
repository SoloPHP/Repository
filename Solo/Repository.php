<?php

namespace Solo;

use Solo\Database;
use Solo\Repository\Interfaces\RepositoryInterface;
use Solo\Repository\FieldSanitizer;
use Solo\Repository\RecordFactory;
use Solo\Repository\QueryBuilder;
use Solo\Repository\QueryParameters;

abstract class Repository implements RepositoryInterface
{
    protected string $table;
    protected string $alias;
    private bool $initialized = false;
    private QueryBuilder $queryBuilder;
    private QueryParameters $queryParams;

    public function __construct(
        protected readonly Database       $db,
        protected readonly FieldSanitizer $fieldSanitizer,
        protected readonly RecordFactory  $recordFactory
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

        $this->queryParams = new QueryParameters(
            select: $this->select(),
            joins: $this->joins(),
            filters: $this->filters()
        );
    }

    protected function select(): string { return '*'; }
    protected function joins(): string { return ''; }
    protected function filters(): array { return []; }

    public function filter(?array $filters): self
    {
        if ($filters === null || empty($filters)) {
            return clone $this;
        }

        $sqlParts = $this->queryBuilder->prepareFilters($filters, $this->filters());

        $clone = clone $this;
        $clone->queryParams = $this->queryParams->withWhere($sqlParts['where']);

        return $clone;
    }

    public function orderBy(?string ...$order): self
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

    public function page(int|string|null $page): self
    {
        if ($page === null) {
            return clone $this;
        }

        $clone = clone $this;
        $clone->queryParams = $this->queryParams->withPage((int)$page);
        return $clone;
    }

    public function perPage(int|string|null $perPage): self
    {
        if ($perPage === null) {
            return clone $this;
        }

        $clone = clone $this;
        $clone->queryParams = $this->queryParams->withPerPage((int)$perPage);
        return $clone;
    }

    public function primaryKey(string $primaryKey): self
    {
        $clone = clone $this;
        $clone->queryParams = $this->queryParams->withPrimaryKey($primaryKey);
        return $clone;
    }

    public function create(array $data, bool $sanitizeFields = false): string|false
    {
        if ($sanitizeFields) {
            $data = $this->fieldSanitizer->sanitize($this->table, $data);
        }
        $this->db->query("INSERT INTO ?t SET ?A", $this->table, $data);
        return $this->db->lastInsertId();
    }

    public function update(int|array $id, array $data, bool $sanitizeFields = false): int
    {
        if ($sanitizeFields) {
            $data = $this->fieldSanitizer->sanitize($this->table, $data);
        }
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

    public function read(bool $readOne = false): mixed
    {
        $query = $this->queryBuilder->buildSelect($this->queryParams);
        $this->db->query($query);
        return $readOne
            ? $this->db->fetchObject()
            : $this->db->fetchAll($this->queryParams->getPrimaryKey());
    }

    public function readOne(): ?object
    {
        $clone = clone $this;
        $clone->queryParams = $this->queryParams
            ->withPage(1)
            ->withPerPage(1);
        return $clone->read(true);
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