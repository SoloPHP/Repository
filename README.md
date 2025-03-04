# Base Repository Class

[![Latest Version](https://img.shields.io/badge/version-2.1.0-blue.svg)](https://github.com/solophp/repository)
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
- Full CRUD and batch operations support
- Transaction support
- IDE-friendly with type safety
- Zero config for basic usage

## Interface Methods

CRUD and query methods:

```php
interface RepositoryInterface
{
    // Create operations
    public function create(array $data): ?object;
    public function createMany(array $records): array;

    // Update operations
    public function update(int $id, array $data): ?object;
    public function updateMany(array $ids, array $data): array;
    public function patch(int $id, array $data): ?object;
    public function patchMany(array $ids, array $data): array;

    // Delete operations
    public function delete(int $id): int;
    public function deleteMany(array $ids): int;

    // Direct find operations
    public function findById(int $id): ?object;
    public function findByIds(array $ids): array;
    public function findBy(array $criteria): array;
    public function findOneBy(array $criteria): ?object;

    // Query builder operations
    public function get(): array;
    public function getOne(): ?object;
    public function getAll(): array;

    // Query building methods
    public function withFilter(?array $filters): self;
    public function withOrderBy(?string ...$order): self;
    public function withSorting(?string $order, ?string $direction = 'ASC'): self;
    public function withPage(?int $page, int $default = 1): self;
    public function withLimit(?int $limit, int $default = 25): self;
    public function withPrimaryKey(string $primaryKey): self;
    public function withDistinct(bool $distinct = true): self;
    
    // Additional methods
    public function count(): int;
    public function exists(array $filters = []): bool;
    public function createEmptyRecord(): object;
    
    // Transaction management
    public function beginTransaction(): bool;
    public function commit(): bool;
    public function rollback(): bool;
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
                where: 'AND p.id IN ?a'
            ),
            
            // Filter with negation
            '!id' => new FilterConfig(
                where: 'AND p.id NOT IN ?a'
            ),
            
            // Boolean filter
            'enabled' => new FilterConfig(
                where: 'AND p.enabled = ?i'
            ),
            
            // Filter with additional data
            'category_id' => new FilterConfig(
                where: 'AND c.id IN ?a',
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
}
```

## Usage Examples

### Creating Records

```php
// Create single record
$product = $repository->create([
    'name' => 'New Product',
    'enabled' => 1
]); // Returns created record object or null

// Create multiple records
$products = $repository->createMany([
    ['name' => 'Product 1', 'enabled' => 1],
    ['name' => 'Product 2', 'enabled' => 1]
]); // Returns array of created records
```

### Finding and Getting Records

```php
// Direct find operations
$product = $repository->findById(1);
$products = $repository->findByIds([1, 2, 3]);
$products = $repository->findBy(['status' => 'active']);
$product = $repository->findOneBy(['email' => 'test@example.com']);

// Query builder operations
$products = $repository
    ->withFilter([
        'enabled' => 1,
        'category_id' => [1, 2, 3]
    ])
    ->get();

// Using search functionality
$products = $repository
    ->withFilter([
        'search' => 'keyword'           // Search in default field
    ])
    ->get();

$products = $repository
    ->withFilter([
        'search' => 'id:12345'          // Search in specific field
    ])
    ->get();

// Get with pagination
$products = $repository
    ->withPage(2)
    ->withLimit(20)
    ->get();

// Get with sorting
$products = $repository
    ->withOrderBy('name', 'created_at DESC')
    ->get();

// Alternative sorting method
$products = $repository
    ->withSorting('name', 'DESC')
    ->get();

// Get with DISTINCT
$products = $repository
    ->withDistinct()
    ->get();

// Get one record
$product = $repository
    ->withFilter(['status' => 'active'])
    ->getOne();

// Get all records
$products = $repository->getAll();
```

### Search Methods

The repository provides two approaches to retrieving data:

1. Direct find methods:
- `findById()` - quick lookup by primary key
- `findByIds()` - quick lookup by multiple primary keys
- `findBy()` - simple search by criteria array
- `findOneBy()` - get first record matching criteria

2. Query builder methods:
- `get()` - fetch records using current query state
- `getOne()` - fetch single record using query state
- `getAll()` - fetch all records without pagination

Use direct find methods for simple lookups and the query builder for complex queries with filtering, sorting, and pagination.

### Update vs Patch

- `update()` is used for full record updates, expecting all fields to be provided
- `patch()` is used for partial updates, updating only specified fields
- Both methods return the updated record(s)
- Both methods validate that data array is not empty

### Updating Records

```php
// Update single record
$updated = $repository->update(1, [
    'name' => 'Updated Name',
    'status' => 'active'
]); // Returns updated record or null

// Update multiple records
$updatedRecords = $repository->updateMany([1, 2, 3], [
    'status' => 'inactive'
]); // Returns array of updated records

// Patch single record
$patched = $repository->patch(1, [
    'status' => 'active'
]); // Returns updated record or null

// Patch multiple records
$patchedRecords = $repository->patchMany([1, 2, 3], [
    'status' => 'active'
]); // Returns array of updated records
```

### Deleting Records

```php
// Delete single record
$affected = $repository->delete(1);

// Delete multiple records
$affected = $repository->deleteMany([1, 2, 3]);
```

### Using Transactions

```php
try {
    $repository->beginTransaction();
    
    $product = $repository->create([
        'name' => 'New Product',
        'enabled' => 1
    ]);
    
    if ($product) {
        $updated = $categoryRepo->update($product->id, [
        'category_id' => 2
    ]);
    }
    
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

The search functionality provides flexible ways to search across multiple fields:

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
- solophp/database ^2.8

## License

MIT