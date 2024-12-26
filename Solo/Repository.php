<?php

namespace Solo;

use Exception;
use Solo\Database;
use Solo\Repository\FieldSanitizer;
use Solo\Repository\Interfaces\RepositoryInterface;
use Solo\Repository\QueryBuilder;
use Solo\Repository\RecordFactory;

abstract class Repository extends QueryBuilder implements RepositoryInterface
{
    protected string $table = '';
    protected string $alias;
    protected string $from = '';

    public function __construct(
        protected Database                $db,
        protected FieldSanitizer $fieldSanitizer,
        protected RecordFactory  $recordFactory
    )
    {
        parent::__construct($db);
        $this->initialize();
    }

    private function initialize(): void
    {
        if (!isset($this->table)) {
            throw new Exception('The required value $table was not set');
        }
        $this->alias = $this->alias ?? $this->table[0];
        $this->from = $this->db->prepare("FROM ?t AS $this->alias", $this->table);
        $this->setSelect($this->select());
        $this->setJoins($this->joins());
        $this->setFilters($this->filters());
    }

    protected function select(): string { return '*'; }
    protected function joins(): string { return ''; }
    protected function filters(): array { return []; }

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
        $this->db->query("UPDATE ?t SET ?A WHERE id IN(?a)", $this->table, $data, (array)$id);
        return $this->db->rowCount();
    }

    public function delete(int $id): int
    {
        $this->db->query("DELETE FROM ?t WHERE id = ?i LIMIT 1", $this->table, $id);
        return $this->db->rowCount();
    }

    public function read(bool $readOne = false): mixed
    {
        $query = $this->buildQuery();
        $this->db->query($query);
        return $readOne ? $this->db->fetchObject() : $this->db->fetchAll($this->primaryKey);
    }

    public function readOne(): ?object
    {
        $this->limit(1, 1);
        return $this->read(true);
    }

    public function readAll(): array
    {
        $this->clearLimit();
        return $this->read();
    }

    public function count(): int
    {
        $query = $this->buildCountQuery();
        $this->db->query($query);
        return $this->db->fetchObject('count');
    }

    public function createEmptyRecord(): object
    {
        return $this->recordFactory->createEmpty($this->table);
    }
}