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

    protected function select(): string
    {
        return '*';
    }

    protected function joins(): string
    {
        return '';
    }

    protected function filters(): array
    {
        return [];
    }

    public function create(array $data): ?object
    {
        $this->db->query("INSERT INTO ?t SET ?A", $this->table, $data);

        if ($id = $this->db->lastInsertId()) {
        return $this->findById((int)$id);
    }

        if (!isset($data['id'])) {
            throw new \RuntimeException("ID must be provided for tables without auto_increment");
        }

        return $this->findById((int)$data['id']);
    }

    public function createMany(array $records): array
    {
        if (empty($records)) {
            throw new \InvalidArgumentException('Records array cannot be empty');
        }

        $this->beginTransaction();

        try {
            $createdIds = [];

            foreach ($records as $data) {
                $this->db->query("INSERT INTO ?t SET ?A", $this->table, $data);

                $id = $this->db->lastInsertId();
                if ($id) {
                    $createdIds[] = (int)$id;
                } elseif (isset($data['id'])) {
                    $createdIds[] = (int)$data['id'];
                } else {
                    throw new \RuntimeException("ID must be provided for tables without auto_increment");
                }
            }

            $this->commit();
            return $this->findBy(['id' => $createdIds]);

        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function update(int $id, array $data): ?object
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Data cannot be empty for update operation');
        }

        $this->db->query(
            "UPDATE ?t SET ?A WHERE id = ?i",
            $this->table,
            $data,
            $id
        );

        $affected = $this->db->rowCount();
        return $affected ? $this->findById($id) : null;
    }

    public function updateMany(array $ids, array $data): array
    {
        if (empty($ids)) {
            throw new \InvalidArgumentException('IDs array cannot be empty');
        }

        if (empty($data)) {
            throw new \InvalidArgumentException('Data cannot be empty for update operation');
        }

        $this->db->query(
            "UPDATE ?t SET ?A WHERE id IN(?a)",
            $this->table,
            $data,
            $ids
        );

        $affected = $this->db->rowCount();
        return $affected ? $this->findBy(['id' => $ids]) : [];
    }

    public function patch(int $id, array $data): ?object
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Data cannot be empty for patch operation');
        }

        $this->db->query(
            "UPDATE ?t SET ?A WHERE id = ?i",
            $this->table,
            $data,
            $id
        );

        $affected = $this->db->rowCount();
        return $affected ? $this->findById($id) : null;
    }

    public function patchMany(array $ids, array $data): array
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Data cannot be empty for patch operation');
        }

        if (empty($ids)) {
            throw new \InvalidArgumentException('IDs array cannot be empty');
        }

        $this->db->query(
            "UPDATE ?t SET ?A WHERE id IN(?a)",
            $this->table,
            $data,
            $ids
        );

        $affected = $this->db->rowCount();
        return $affected ? $this->findBy(['id' => $ids]) : [];
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

    public function deleteMany(array $ids): int
    {
        if (empty($ids)) {
            throw new \InvalidArgumentException('IDs array cannot be empty');
        }

        $this->db->query(
            "DELETE FROM ?t WHERE id IN(?a)",
            $this->table,
            $ids
        );
        return $this->db->rowCount();
    }

    public function findById(int $id): ?object
    {
        $this->db->query(
            "SELECT * FROM ?t WHERE id = ?i LIMIT 1",
            $this->table,
            $id
        );
        $result = $this->db->fetchObject();

        return $result === false ? null : $result;
    }

    public function findBy(array $criteria): array
    {
        return $this->withFilter($criteria)->get();
    }

    public function findOneBy(array $criteria): ?object
    {
        return $this->withFilter($criteria)->getOne();
    }

    public function get(): array
    {
        $query = $this->queryBuilder->buildSelect($this->queryParams);
        $this->db->query($query);
        return $this->db->fetchAll($this->queryParams->getPrimaryKey());
    }

    public function getOne(): ?object
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

    public function getAll(): array
    {
        $clone = clone $this;
        $clone->queryParams = $this->queryParams->clearLimit();
        return $clone->get();
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
            return clone $this;
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

    public function withDistinct(bool $distinct = true): self
    {
        $clone = clone $this;
        $clone->queryParams = $this->queryParams->withDistinct($distinct);
        return $clone;
    }

    public function withPrimaryKey(string $primaryKey): self
    {
        $clone = clone $this;
        $clone->queryParams = $this->queryParams->withPrimaryKey($primaryKey);
        return $clone;
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