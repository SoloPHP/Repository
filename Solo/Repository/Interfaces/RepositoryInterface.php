<?php

namespace Solo\Repository\Interfaces;

/**
 * Interface for repository pattern implementation
 *
 * Provides CRUD operations and query building capabilities
 */
interface RepositoryInterface
{
    /**
     * Whether DISTINCT should be applied to SELECT queries
     *
     * @param bool $distinct Whether to enable DISTINCT (true by default)
     * @return self New instance with DISTINCT flag set
     */
    public function withDistinct(bool $distinct = true): self;

    /**
     * Apply filters to the query
     *
     * @param array|null $filters Associative array of filters where key is filter name and value is filter value
     * @return self New instance with applied filters
     */
    public function withFilter(?array $filters): self;

    /**
     * Set the result ordering
     *
     * @param string ...$order Multiple order expressions (e.g. 'name ASC', 'created_at DESC')
     * @return self New instance with applied ordering
     */
    public function withOrderBy(?string ...$order): self;

    /**
     * Set the page number for pagination
     *
     * @param string|null $page Page number (1-based)
     * @return self New instance with applied pagination
     */
    public function withPage(?string $page): self;

    /**
     * Set the number of items per page
     *
     * @param string|null $perPage Number of items per page
     * @return self New instance with applied limit
     */
    public function withPerPage(?string $perPage): self;

    /**
     * Set the primary key field for result indexing
     *
     * @param string $primaryKey Name of the primary key field
     * @return self New instance with set primary key
     */
    public function withPrimaryKey(string $primaryKey): self;

    /**
     * Create a new record
     *
     * @param array $data Record data
     * @return string|false The ID of created record or false on failure
     */
    public function create(array $data): string|false;

    /**
     * Update existing record(s)
     *
     * @param string|array $id Single ID or array of IDs to update
     * @param array $data Updated data
     * @return int Number of affected rows
     */
    public function update(string|array $id, array $data): int;

    /**
     * Delete a record
     *
     * @param string $id Record ID
     * @return int Number of affected rows
     */
    public function delete(string $id): int;

    /**
     * Read records based on current query state
     *
     * @return array Array of records
     */
    public function read(): array;

    /**
     * Read a single record based on current query state
     *
     * @return object|null Found record or null if not found
     */
    public function readOne(): ?object;

    /**
     * Read all records without pagination
     *
     * @return array Array of all records
     */
    public function readAll(): array;

    /**
     * Count records based on current query state
     *
     * @return int Number of records
     */
    public function count(): int;

    /**
     * Check if records exist based on given filters
     *
     * @param array $filters Key-value pairs of filter conditions
     * @return bool True if records exist, false otherwise
     */
    public function exists(array $filters = []): bool;

    /**
     * Create an empty record with default values based on schema
     *
     * @return object Empty record object
     */
    public function createEmptyRecord(): object;

    /**
     * Begin a database transaction
     *
     * @return bool Success status
     */
    public function beginTransaction(): bool;

    /**
     * Commit the current transaction
     *
     * @return bool Success status
     */
    public function commit(): bool;

    /**
     * Rollback the current transaction
     *
     * @return bool Success status
     */
    public function rollback(): bool;
}