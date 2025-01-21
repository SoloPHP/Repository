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
     * Create a new record
     *
     * @param array $data Record data
     * @return object|null Created record or null on failure
     * @throws \RuntimeException If ID is required but not provided
     */
    public function create(array $data): ?object;

    /**
     * Create multiple records
     *
     * @param array[] $records Array of record data arrays
     * @return array Array of created records
     * @throws \InvalidArgumentException If records array is empty
     * @throws \RuntimeException If ID is required but not provided
     */
    public function createMany(array $records): array;

    /**
     * Update existing record
     *
     * @param int $id ID of record to update
     * @param array $data Updated data
     * @return object|null Updated record or null if not found/updated
     * @throws \InvalidArgumentException If data array is empty
     */
    public function update(int $id, array $data): ?object;

    /**
     * Update multiple records
     *
     * @param array $ids Array of record IDs to update
     * @param array $data Updated data
     * @return array Array of updated records
     * @throws \InvalidArgumentException If IDs array or data array is empty
     */
    public function updateMany(array $ids, array $data): array;

    /**
     * Partially update a record with only provided fields
     *
     * @param int $id ID of record to patch
     * @param array $data Fields to update
     * @return object|null Updated record or null if not found/updated
     * @throws \InvalidArgumentException If data array is empty
     */
    public function patch(int $id, array $data): ?object;

    /**
     * Partially update multiple records with only provided fields
     *
     * @param array $ids Array of record IDs to patch
     * @param array $data Fields to update
     * @return array Array of updated records
     * @throws \InvalidArgumentException If IDs array or data array is empty
     */
    public function patchMany(array $ids, array $data): array;

    /**
     * Delete a record
     *
     * @param int $id Record ID
     * @return int Number of affected rows
     */
    public function delete(int $id): int;

    /**
     * Delete multiple records
     *
     * @param array $ids Array of record IDs to delete
     * @return int Number of affected rows
     * @throws \InvalidArgumentException If IDs array is empty
     */
    public function deleteMany(array $ids): int;

    /**
     * Find record by ID
     *
     * @param int $id Record ID
     * @return object|null Found record or null if not found
     */
    public function findById(int $id): ?object;

    /**
     * Find multiple records by IDs
     *
     * @param array $ids Array of record IDs
     * @return array Array of found records
     */
    public function findByIds(array $ids): array;

    /**
     * Find records by criteria
     *
     * @param array $criteria Search criteria
     * @return array Array of found records
     */
    public function findBy(array $criteria): array;

    /**
     * Find single record by criteria
     *
     * @param array $criteria Search criteria
     * @return object|null Found record or null if not found
     */
    public function findOneBy(array $criteria): ?object;

    /**
     * Get records based on current query state
     *
     * @return array Array of records
     */
    public function get(): array;

    /**
     * Get single record based on current query state
     *
     * @return object|null Found record or null if not found
     */
    public function getOne(): ?object;

    /**
     * Get all records without pagination
     *
     * @return array Array of all records
     */
    public function getAll(): array;

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
     * Set sorting by field and direction
     *
     * @param string|null $order Field name for sorting
     * @param string|null $direction Sort direction ('ASC' or 'DESC')
     * @return self New instance with applied sorting
     */
    public function withSorting(?string $order, ?string $direction = 'ASC'): self;

    /**
     * Set the page number for pagination
     *
     * @param int|null $page Page number (1-based)
     * @param int $default Default page if none provided
     * @return self New instance with applied pagination
     */
    public function withPage(?int $page, int $default = 1): self;

    /**
     * Set the number of items per page
     *
     * @param int|null $limit Number of items per page
     * @param int $default Default limit if none provided
     * @return self New instance with applied limit
     */
    public function withLimit(?int $limit, int $default = 25): self;

    /**
     * Set the primary key field for result indexing
     *
     * @param string $primaryKey Name of the primary key field
     * @return self New instance with set primary key
     */
    public function withPrimaryKey(string $primaryKey): self;

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