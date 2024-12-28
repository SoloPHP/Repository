# Base Repository Class

[![Latest Version](https://img.shields.io/badge/version-2.0.0-blue.svg)](https://github.com/solophp/repository)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](https://opensource.org/licenses/MIT)

A flexible base repository class for PHP 8+ with query builder and CRUD operations, featuring immutable architecture and selective loading.

## Installation

```bash
composer require solophp/repository
```

The package will automatically install required dependencies, including [solophp/database](https://github.com/solophp/database).

## Features

- Clean implementation of Repository pattern with immutability
- Fluent QueryBuilder interface with withX methods
- Separation of concerns between QueryParameters, QueryBuilder and FilterConfig
- Type-safe and IDE-friendly
- Automatic field sanitization
- Advanced filtering system:
    - Support for all database types (string, integer, float, array, date)
    - Selective loading with per-filter JOIN and SELECT support
    - Automatic JOIN deduplication
    - Table name prefixing
    - LIKE queries with automatic wildcards
    - Raw SQL parameters
    - Associative arrays for SET clauses
    - Custom filter callbacks
    - Chainable filter conditions
- Built-in pagination
- Sorting support
- JOIN operations support
- Transaction support
- Automatic default values based on database schema
- Zero configuration for basic usage
- Easily extendable

## Interface Methods

### QueryBuilderInterface

Methods for building and manipulating database queries:

```php
interface QueryBuilderInterface 
{
    // Apply filters to the query
    public function withFilter(?array $filters): self;

    // Set the order of results
    public function withOrderBy(?string ...$order): self;

    // Set the page number for pagination
    public function withPage(int|string|null $page): self;

    // Set the number of items per page
    public function withPerPage(int|string|null $perPage): self;

    // Set primary key for result indexing
    public function withPrimaryKey(string $primaryKey): self;
}
```

### RepositoryInterface

CRUD and query methods:

```php
interface RepositoryInterface extends QueryBuilderInterface
{
    // Create a new record
    public function create(array $data, bool $sanitizeFields = false): string|false;

    // Update existing record(s)
    public function update(int|array $id, array $data, bool $sanitizeFields = false): int;

    // Delete a record
    public function delete(int $id): int;

    // Read records based on current query state
    public function read(bool $readOne = false): mixed;

    // Read a single record
    public function readOne(): ?object;

    // Read all records
    public function readAll(): array;

    // Count records based on current query state
    public function count(): int;

    // Create an empty record with default values
    public function createEmptyRecord(): object;

    // Transaction management
    public function beginTransaction(): bool;
    public function commit(): bool;
    public function rollback(): bool;
}
```

## Implementation Example

```php
class ProductsRepository extends Repository
{
    protected string $table = 'products';
    protected string $alias = 'p';

    protected function select(): string
    {
        return '
            p.*,
            c.name AS category_name
        ';
    }

    protected function joins(): string
    {
        return '
            LEFT JOIN categories c ON c.id = p.category_id
        ';
    }

    protected function filters(): array
    {
        return [
            'id' => new FilterConfig(
                where: 'AND p.id IN(?a)'
            ),
            'enabled' => new FilterConfig(
                where: 'AND p.enabled = ?i'
            ),
            'category_id' => new FilterConfig(
                where: 'AND c.id IN(?a)',
                select: 'c.path AS category_path',
                joins: 'LEFT JOIN categories c ON c.id = p.category_id'
            ),
            'search' => new FilterConfig(
                where: fn($value) => $this->buildSearchFilter($value),
                select: '
                    b.name AS brand_name,
                    cn.name AS country_name
                ',
                joins: '
                    LEFT JOIN brands b ON b.id = p.brand_id
                    LEFT JOIN countries cn ON cn.id = p.country_id
                '
            )
        ];
    }

    private function buildSearchFilter(string $value): string 
    {
        $filter = '';
        foreach (explode(' ', $value) as $kw) {
            if ($kw = trim($kw)) {
                $filter .= $this->db->prepare(
                    "AND (p.name LIKE ?l OR b.name LIKE ?l)", 
                    $kw, 
                    $kw
                );
            }
        }
        return $filter;
    }
}
```

## Usage Examples

### Creating Records

```php
// Simple create
$id = $repository->create([
    'name' => 'New Product',
    'enabled' => 1
]);

// Create with field sanitization
$id = $repository->create([
    'name' => 'New Product',
    'enabled' => 1,
    'non_existent_field' => 'value' // will be removed
], true);
```

### Reading Records

```php
// Read with filters
$products = $repository
    ->withFilter([
        'enabled' => 1,
        'category_id' => [1, 2, 3]
    ])
    ->read();

// Read with pagination (accepts both int and string)
$products = $repository
    ->withPage(2)        // or ->withPage('2')
    ->withPerPage(20)    // or ->withPerPage('20')
    ->read();

// Read with sorting
$products = $repository
    ->withOrderBy('name', 'created_at DESC')
    ->read();

// Read one record
$product = $repository
    ->withFilter(['id' => 1])
    ->readOne();

// Read all records
$products = $repository->readAll();

// Count records
$count = $repository
    ->withFilter(['enabled' => 1])
    ->count();
```

### Using Transactions

```php
try {
    $repository->beginTransaction();
    
    $id = $repository->create([
        'name' => 'New Product',
        'enabled' => 1
    ]);
    
    $categoryRepo->update($id, [
        'category_id' => 2
    ]);
    
    $repository->commit();
} catch (\Exception $e) {
    $repository->rollback();
    throw $e;
}
```

### Updating Records

```php
// Update single record
$affected = $repository->update(1, [
    'name' => 'Updated Name'
]);

// Update multiple records
$affected = $repository->update([1, 2, 3], [
    'enabled' => 0
]);

// Update with field sanitization
$affected = $repository->update(1, [
    'name' => 'Updated Name',
    'non_existent_field' => 'value' // will be removed
], true);
```

### Deleting Records

```php
$affected = $repository->delete(1);
```

### Creating Empty Record

```php
$emptyRecord = $repository->createEmptyRecord();
// Returns object with default values based on database schema
```

## Filter Types

The repository supports various placeholder types from solophp/database:

- `?s` - String (quoted)
- `?i` - Integer
- `?f` - Float
- `?a` - Array (for IN clauses)
- `?A` - Associative array (for SET clauses)
- `?t` - Table name (with prefix)
- `?p` - Raw parameter
- `?d` - Date (DateTimeImmutable)
- `?l` - LIKE parameter (adds '%' for LIKE queries)

## Filter Configuration

The FilterConfig class allows you to define complex filters with selective loading:

```php
new FilterConfig(
    where: 'AND field = ?i',    // WHERE condition
    select: 'field AS alias',    // Additional SELECT fields
    joins: 'LEFT JOIN table'     // Required JOINs
);
```

Example configuration with different types of filters:
```php
protected function filters(): array
{
    return [
        // Simple filter
        'id' => new FilterConfig(
            where: 'AND p.id IN(?a)'
        ),
        
        // Filter with additional fields
        'category_id' => new FilterConfig(
            where: 'AND c.id IN(?a)',
            select: 'c.name AS category_name, c.path AS category_path',
            joins: 'LEFT JOIN categories c ON c.id = p.category_id'
        ),
        
        // Complex filter with callback
        'search' => new FilterConfig(
            where: fn($value) => $this->db->prepare(
            "AND (p.name LIKE ?l OR p.sku LIKE ?l)", 
            $value, 
            $value
            ),
            select: 'b.name AS brand_name',
            joins: 'LEFT JOIN brands b ON b.id = p.brand_id'
        ),

        // Date filter
        'created_after' => new FilterConfig(
            where: 'AND p.created_at > ?d'
        ),
        
        // Raw SQL filter
        'custom' => new FilterConfig(
            where: 'AND ?p',
            select: 'CONCAT(field1, field2) AS combined'
        )
    ];
}
```

Each filter can specify its own SELECT fields and JOIN conditions, which will be automatically merged and deduplicated in the final query.

## Requirements

- PHP ^8.2
- PDO extension
- solophp/database ^2.5

## License

MIT