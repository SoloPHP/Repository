<?php

namespace Solo\Repository\Interfaces;

/**
* Interface for repository pattern implementation
* 
* Provides CRUD operations and query building capabilities
*/
interface RepositoryInterface extends QueryBuilderInterface
{
   /**
    * Create a new record
    *
    * @param array $data Record data
    * @param bool $sanitizeFields Whether to sanitize fields based on table schema
    * @return string|false The ID of created record or false on failure
    */
    public function create(array $data, bool $sanitizeFields = false): string|false;

   /**
    * Update existing record(s)
    *
    * @param int|array $id Single ID or array of IDs to update
    * @param array $data Updated data
    * @param bool $sanitizeFields Whether to sanitize fields based on table schema
    * @return int Number of affected rows
    */
    public function update(int|array $id, array $data, bool $sanitizeFields = false): int;

   /**
    * Delete a record
    *
    * @param int $id Record ID
    * @return int Number of affected rows
    */
    public function delete(int $id): int;

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