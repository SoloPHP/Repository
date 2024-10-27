<?php

namespace Solo;

use Solo\Database;
use Exception;
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

    /** Page number for pagination */
    private int $page = 1;

    /** Items per page for pagination */
    private int $perPage = 100;

    /** Use a specific field from database table as the array key for the result objects */
    private string $primaryKey = '';

    /**
     * Repository constructor.
     *
     * @param Database $db Database connection instance
     * @throws Exception If the required table name is not set
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->initialize();
    }

    /**
     * Initialize default values for the repository.
     *
     * @throws Exception If the required table name is not set
     */
    private function initialize(): void
    {
        if (!isset($this->table)) {
            throw new Exception('The required value $table was not passed');
        }

        $this->alias = $this->alias ?? $this->table[0];
        $this->from = $this->db->build("FROM ?t AS $this->alias", $this->table);
        $this->select = "SELECT $this->select";
        $this->buildJoins();
        $this->where = "WHERE 1 $this->where";
        $this->limit = "LIMIT 0, $this->perPage";
        $this->orderBy = $this->orderBy ? "ORDER BY $this->orderBy" : '';
    }

    /**
     * Create a new record in the database.
     *
     * @param array $data Data for the new record
     * @param bool $sanitizeFields Whether to sanitize fields based on the table structure
     * @return string|false The last inserted ID on success, false on failure
     */
    public function create(array $data, bool $sanitizeFields = false)
    {
        if ($sanitizeFields) {
            $data = $this->sanitizeFields($data);
        }
        $this->db->query("INSERT INTO ?t SET ?A", $this->table, $data);
        return $this->db->lastInsertId();
    }

    /**
     * Update an existing record in the database.
     *
     * @param int $id The ID of the record to update
     * @param array $data Data for updating the record
     * @param bool $sanitizeFields Whether to sanitize fields based on the table structure
     * @return int The ID of the updated record
     */
    public function update(int $id, array $data, bool $sanitizeFields = false): int
    {
        if ($sanitizeFields) {
            $data = $this->sanitizeFields($data);
        }
        $this->db->query("UPDATE ?t SET ?A WHERE id = ?i", $this->table, $data, $id);
        return $id;
    }

    /**
     * Delete a record from the database.
     *
     * @param int $id The ID of the record to delete
     * @return int The number of affected rows (1 if deleted, 0 otherwise)
     */
    public function delete(int $id): int
    {
        $this->db->query("DELETE FROM ?t WHERE id = ?i LIMIT 1", $this->table, $id);
        return $this->db->rowCount();
    }

    /**
     * Read records from the database based on the current query state.
     *
     * @param bool $readOne Whether to read a single record (default: false)
     * @return mixed The fetched records or a single record if $readOne is true
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
     * Read a single record from the database.
     *
     * @return mixed The fetched record
     */
    public function readOne()
    {
        $this->limit = 'LIMIT 1';
        return $this->read(true);
    }

    /**
     * Read all records from the database.
     *
     * @return mixed The fetched records
     */
    public function readAll()
    {
        $this->limit = '';
        return $this->read();
    }

    /**
     * Count the number of records in the database.
     *
     * @return int The count of records
     */
    public function count(): int
    {
        $select = 'SELECT COUNT(*) AS count';
        $query = "$select $this->from $this->joins $this->where";

        $this->db->query($query);
        return $this->db->result('count');
    }

    /**
     * Sanitize the data by removing any fields that not in the table.
     *
     * @param array $data Data to be sanitized
     * @return array The sanitized data
     */
    private function sanitizeFields(array $data): array
    {
        $this->db->query("DESCRIBE ?t", $this->table);
        $fields = array_column($this->db->results(), 'Field');

        return array_intersect_key($data, array_flip($fields));
    }

    /**
     * Create an empty record template based on the table structure.
     *
     * @return object An empty record object with default values
     */
    public function createEmptyRecord(): object
    {
        $this->db->query("DESCRIBE ?t", $this->table);
        $emptyRecord = new stdClass();

        foreach ($this->db->results() as $row) {
            if (
                $row->Field == 'id' ||
                $row->Key == 'PRI' ||
                in_array($row->Default, ['current_timestamp()', 'current_timestamp', 'CURRENT_TIMESTAMP'])
            ) {
                continue;
            }

            $emptyRecord->{$row->Field} = $this->convertMySQLType($row->Type, $row->Default);

        }

        return $emptyRecord;
    }

    /**
     * Convert MySQL type to PHP type.
     *
     * @param string $type MySQL type
     * @param mixed $value Value to be converted
     * @return mixed The converted value
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
     * Set filter conditions for the query.
     *
     * @param array $filters Filters to be applied
     * @return self A clone of the repository instance with applied filters
     */
    public function filter(array $filters): self
    {
        $clone = clone $this;
        $sqlParts = $this->buildFilters($filters, $clone->where, $clone->joins);
        $clone->where = $sqlParts['where'];
        $clone->joins = $sqlParts['joins'];

        return $clone;
    }

    /**
     * Set the ORDER BY clause for the query.
     *
     * @param string ...$order Fields to order by
     * @return self A clone of the repository instance with applied order
     */
    public function orderBy(string ...$order): self
    {
        $clone = clone $this;

        $clone->orderBy = 'ORDER BY ' . implode(', ', array_map(fn($s) => "$this->alias.$s", $order));

        return $clone;
    }

    /**
     * Set the primary key field for the result objects.
     *
     * @param string $primaryKey The primary key field
     * @return self A clone of the repository instance with the specified primary key
     */
    public function primaryKey(string $primaryKey): self
    {
        $clone = clone $this;
        $clone->primaryKey = $primaryKey;

        return $clone;
    }

    /**
     * Set pagination limits for the query.
     *
     * @param int $page Page number (starting from 1)
     * @param int $perPage Number of items per page
     * @return self A clone of the repository instance with applied pagination limits
     */
    public function limit(int $page, int $perPage): self
    {
        $clone = clone $this;
        $clone->page = max(1, $page);
        $clone->perPage = max(1, $perPage);
        $clone->limit = "LIMIT " . (($clone->page - 1) * $clone->perPage) . ", $clone->perPage";

        return $clone;
    }

    /**
     * Build the JOINs based on the current configuration.
     *
     * @return void
     * @throws Exception If build fails
     */
    protected function buildJoins(): void
    {
        // To be implemented by child classes as necessary.
    }

    /**
     * Build the filters for the query.
     *
     * @param array $filters The filters to be applied
     * @param string $where The current WHERE clause
     * @param string $joins The current JOINs clause
     * @return array The modified WHERE and JOINs clauses
     * @throws Exception If build fails
     */
    protected function buildFilters(array $filters, string $where, string $joins): array
    {
        // To be implemented by child classes as necessary.
        return ['where' => $where, 'joins' => $joins];
    }
}