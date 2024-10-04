<?php

namespace Solo;

use stdClass;

class Repository
{
    protected Database $db;

    /** Table name (required) */
    protected string $table;

    /** Table alias */
    private string $alias;

    /** SELECT query part */
    protected string $select = '*';

    /** FROM query part */
    private string $from;

    /** JOINs query part */
    protected string $joins = '';

    /** WHERE query part */
    protected string $where = '';

    /** ORDER BY query part */
    protected string $orderBy = '';

    /** LIMIT query part */
    private string $limit = '';

    /** Page number */
    private int $page = 1;

    /** Items per page */
    private int $perPage = 100;

    /** Use a specific field from database table as the array key for the result objects */
    private string $primaryKey = '';

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->initialize();
    }

    /** Initialize default values
     * @throws \Exception
     */
    private function initialize(): void
    {
        if (!isset($this->table)) {
            throw new \Exception('The required value $table was not passed');
        }
        $this->alias = $this->alias ?? $this->table[0];
        $this->from = $this->db->prepare("FROM ?t AS $this->alias", $this->table);
        $this->select = "SELECT $this->select";
        $this->where = "WHERE 1 $this->where";
        $this->limit = "LIMIT 0, $this->perPage";
        $this->orderBy = $this->orderBy ? "ORDER BY $this->orderBy" : '';
    }

    /**
     * Create a new record
     *
     * @param array $data
     * @param bool $cleanData
     * @return string|false
     */
    public function create(array $data, bool $cleanData = false)
    {
        if ($cleanData) {
            $data = $this->cleanData($data);
        }
        $this->db->query("INSERT INTO ?t SET ?A", $this->table, $data);
        return $this->db->lastInsertId();
    }

    /**
     * Update an existing record
     *
     * @param int $id
     * @param array $data
     * @param bool $cleanData
     * @return int
     */
    public function update(int $id, array $data, bool $cleanData = false): int
    {
        if ($cleanData) {
            $data = $this->cleanData($data);
        }
        $this->db->query("UPDATE ?t SET ?A WHERE id = ?i", $this->table, $data, $id);
        return $id;
    }

    /**
     * Delete a record
     *
     * @param int $id
     * @return int
     */
    public function delete(int $id): int
    {
        $this->db->query("DELETE FROM ?t WHERE id = ?i LIMIT 1", $this->table, $id);
        return $this->db->rowCount();
    }

    /**
     * Read records
     *
     * @param bool $readOne
     * @return mixed
     */
    public function read(bool $readOne = false)
    {
        $query = "$this->select
        $this->from
        $this->joins 
        $this->where 
        $this->orderBy
        $this->limit";

        $this->db->query($query);

        return $readOne ? $this->db->result() : $this->db->results($this->primaryKey);
    }

    /**
     * Read one record
     *
     * @return mixed
     */
    public function readOne()
    {
        $this->limit = 'LIMIT 1';
        return $this->read(true);
    }

    /**
     * Read all records
     *
     * @return mixed
     */
    public function readAll()
    {
        $this->limit = '';
        return $this->read();
    }

    /**
     * Count records
     *
     * @return int
     */
    public function count(): int
    {
        $select = 'SELECT COUNT(*) AS count';
        $query = "$select $this->from $this->joins $this->where";

        $this->db->query($query);
        return $this->db->result('count');
    }

    /**
     * Clean data by removing fields not in the table
     *
     * @param array $data
     * @return array
     */
    private function cleanData(array $data): array
    {
        $this->db->query("DESCRIBE ?t", $this->table);
        $cleanedData = [];
        foreach ($this->db->results() as $row) {
            if (array_key_exists($row->Field, $data)) {
                $cleanedData[$row->Field] = $data[$row->Field];
            }
        }
        return $cleanedData;
    }

    /**
     * Create an empty record template
     *
     * @return object
     */
    public function createEmptyRecord(): object
    {
        $this->db->query("DESCRIBE ?t", $this->table);
        $emptyRecord = new stdClass();
        foreach ($this->db->results() as $row) {
            if ($row->Field === 'id' && $row->Key === 'PRI') {
                continue;
            }
            $emptyRecord->{$row->Field} = $this->convertMySQLType($row->Type, $row->Default);
        }
        return $emptyRecord;
    }

    /**
     * Convert MySQL type to PHP type
     *
     * @param string $type
     * @param mixed $value
     * @return mixed
     */
    private function convertMySQLType(string $type, $value)
    {
        if ($value === null) {
            return null;
        }
        if ($value === '') {
            return '';
        }

        $mainType = strtoupper(explode('(', $type)[0]);
        switch ($mainType) {
            case 'INT':
            case 'TINYINT':
            case 'SMALLINT':
            case 'MEDIUMINT':
            case 'BIGINT':
                return (int)$value;
            case 'DECIMAL':
            case 'NUMERIC':
            case 'FLOAT':
            case 'DOUBLE':
                return (float)$value;
            default:
                return $value;
        }
    }

    /**
     * Set filter conditions
     *
     * @param array $filters
     * @return self
     */
    public function setFilter(array $filters): self
    {
        $clone = clone $this;
        $sqlParts = $this->generateSQLQueryFromFilters($filters, $clone->where, $clone->joins);
        $clone->where = $sqlParts['where'];
        $clone->joins = $sqlParts['joins'];

        return $clone;
    }

    /**
     * Set ORDER BY clause
     *
     * @param string ...$order
     * @return self
     */
    public function setOrderBy(string ...$order): self
    {
        $clone = clone $this;
        foreach ($order as &$s) {
            $s = "$this->alias.$s";
        }
        $clone->orderBy = 'ORDER BY ' . implode(', ', $order);
        return $clone;
    }

    /**
     * Set the primary key field
     *
     * @param string $primaryKey
     * @return self
     */
    public function setPrimaryKey(string $primaryKey): self
    {
        $clone = clone $this;
        $clone->primaryKey = $primaryKey;

        return $clone;
    }

    /**
     * Set pagination limits
     *
     * @param int $page
     * @param int $perPage
     * @return self
     */
    public function setLimit(int $page, int $perPage): self
    {
        $clone = clone $this;
        $clone->page = max(1, $page);
        $clone->perPage = max(1, $perPage);
        $clone->limit = $this->db->prepare('LIMIT ?i, ?i', ($clone->page - 1) * $clone->perPage, $clone->perPage);

        return $clone;
    }

    /**
     * Generate SQL query parts from filters
     *
     * @param array $filters
     * @param string $where
     * @param string $joins
     * @return array
     */
    protected function generateSQLQueryFromFilters(array $filters, string $where, string $joins): array
    {
        return ['where' => $this->where, 'joins' => $this->joins];
    }
}