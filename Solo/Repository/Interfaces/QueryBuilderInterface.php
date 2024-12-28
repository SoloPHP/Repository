<?php

namespace Solo\Repository\Interfaces;

/**
* Interface for query building operations
* 
* Provides methods for constructing database queries in an immutable way
*/
interface QueryBuilderInterface
{
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
    * @param int|string|null $page Page number (1-based)
    * @return self New instance with applied pagination
    */
    public function withPage(int|string|null $page): self;

   /**
    * Set the number of items per page
    *
    * @param int|string|null $perPage Number of items per page
    * @return self New instance with applied limit
    */
    public function withPerPage(int|string|null $perPage): self;

   /**
    * Set the primary key field for result indexing
    *
    * @param string $primaryKey Name of the primary key field
    * @return self New instance with set primary key
    */
    public function withPrimaryKey(string $primaryKey): self;
}