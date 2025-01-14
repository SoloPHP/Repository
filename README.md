# Base Repository Class

[![Latest Version](https://img.shields.io/badge/version-2.4.0-blue.svg)](https://github.com/solophp/repository)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](https://opensource.org/licenses/MIT)

A flexible base repository class for PHP 8+ with query builder and CRUD operations, featuring immutable architecture and selective loading.

## Installation

```bash
composer require solophp/repository
```

The package will automatically install required dependencies, including [solophp/database](https://github.com/solophp/database).

## Features

- Repository pattern with immutable architecture
- Query building with filters, sorting and pagination
- Flexible filtering system:
    - Multiple data type support
    - Selective loading (JOIN/SELECT)
    - Table prefixing
    - LIKE queries and raw SQL
    - Custom callbacks
    - Advanced search functionality with field selection
- Support for transactions
- IDE-friendly with type safety
- Zero config for basic usage

## Interface Methods

CRUD and query methods:

```php
interface RepositoryInterface
{
    // Create a new record
    public function create(array $data): int|false;

    // Update existing record(s)
    public function update(int|array $id, array $data): int;

    // Delete a record
    public function delete(int $id): int;

    // Read records based on current query state
    public function read(): array;

    // Read a single record based on current query state
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

    // Query building methods
    public function withFilter(?array $filters): self;
    public function withOrderBy(?string ...$order): self;
    public function withSorting(?string $order, string $direction = 'ASC'): self;
    public function withPage(?int $page, int $default = 1): self;
    public function withLimit(?int $limit, int $default = 25): self;
    public function withPrimaryKey(string $primaryKey): self;
    public function withDistinct(bool $distinct = true): self;
}
```

## Filter Configuration

The FilterConfig class allows you to define complex filters with selective loading:

```php
new FilterConfig(
    where: 'AND field = ?i',      // WHERE condition or Closure
    select: 'field AS alias',      // Additional SELECT fields
    joins: 'LEFT JOIN table',      // Required JOINs
    search: ['field1', 'field2']   // Searchable fields
);
```

## Implementation Example

```php
class ProductsRepository extends Repository
{
    protected string $table = 'products';
    protected string $alias = 'p';
    protected bool $distinct = false;
    protected ?array $orderBy = ['created_at DESC', 'id DESC'];

    protected function select(): string
    {
        return '
            p.*,
            c.name AS category_name,
            b.name AS brand_name
        ';
    }

    protected function joins(): string
    {
        return '
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN brands b ON b.id = p.brand_id
        ';
    }

    protected function filters(): array
    {
        return [
            // Simple filter
            'id' => new FilterConfig(
                where: 'AND p.id IN(?a)'
            ),
            
            // Filter with negation
            '!id' => new FilterConfig(
                where: 'AND p.id NOT IN(?a)'
            ),
            
            // Boolean filter
            'enabled' => new FilterConfig(
                where: 'AND p.enabled = ?i'
            ),
            
            // Filter with additional data
            'category_id' => new FilterConfig(
                where: 'AND c.id IN(?a)',
                select: 'c.path AS category_path',
                joins: 'LEFT JOIN categories c ON c.id = p.category_id'
            ),
            
            // Search functionality with multiple fields
            'search' => new FilterConfig(
                search: ['name', 'id', 'sku']
            ),
            
            // Complex filter with callback
            'custom_search' => new FilterConfig(
                where: fn($value) => $this->buildCustomSearch($value),
                select: 'b.name AS brand_name',
                joins: 'LEFT JOIN brands b ON b.id = p.brand_id'
            )
        ];
    }

    private function buildCustomSearch(string $value): string 
    {
        //some logic
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
]); // Returns int ID or false
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

// Using search functionality
$products = $repository
    ->withFilter([
        'search' => 'keyword'           // Search in default field (first in array)
    ])
    ->read();

$products = $repository
    ->withFilter([
        'search' => 'id:12345'          // Search in specific field
    ])
    ->read();

$products = $repository
    ->withFilter([
        'search' => 'red shirt'         // Multi-word search
    ])
    ->read();

// Read with pagination
$products = $repository
    ->withPage(2)             // or ->withPage(null) for default page 1
    ->withLimit(20)          // or ->withLimit(null) for default 25 items
    ->read();

// Read with custom defaults
$products = $repository
    ->withPage(null, 5)       // use page 5 as default
    ->withLimit(null, 50)    // use 50 items as default
    ->read();

// Read with sorting
$products = $repository
    ->withOrderBy('name', 'created_at DESC')
    ->read();

// Alternative sorting method
$products = $repository
    ->withSorting('name', 'DESC')
    ->read();

// Read with DISTINCT
$products = $repository
    ->withDistinct()
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
```

### Deleting Records

```php
$affected = $repository->delete(1);
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

## Advanced Search Configuration

The new search functionality provides flexible ways to search across multiple fields:

```php
protected function filters(): array
{
    return [
        // Basic search across multiple fields
        'search' => new FilterConfig(
            search: ['name', 'sku', 'description']
            ),
        
        // Combining search with additional data
        'advanced_search' => new FilterConfig(
            search: ['name', 'sku'],
            select: 'b.name AS brand_name',
            joins: 'LEFT JOIN brands b ON b.id = p.brand_id'
        )
    ];
}
```

Search features include:
- Multiple field search support
- Field-specific search using `field:value` syntax
- Default field fallback (first field in array)
- Word-by-word matching
- Automatic LIKE query generation

## Requirements

- PHP ^8.2
- PDO extension
- solophp/database ^2.5

## License

MIT