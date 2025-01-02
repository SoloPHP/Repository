# Base Repository Class

[![Latest Version](https://img.shields.io/badge/version-2.2.0-blue.svg)](https://github.com/solophp/repository)
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
- Support for transactions
- IDE-friendly with type safety
- Zero config for basic usage

## Interface Methods

CRUD and query methods:

```php
interface RepositoryInterface
{
    // Create a new record
    public function create(array $data): string|false;

    // Update existing record(s)
    public function update(string|array $id, array $data): int;

    // Delete a record
    public function delete(string $id): int;

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
    public function withPage(?string $page): self;
    public function withPerPage(?string $perPage): self;
    public function withPrimaryKey(string $primaryKey): self;
    public function withDistinct(bool $distinct = true): self;
}
```

## Implementation Example

```php
class ProductsRepository extends Repository
{
    protected string $table = 'products';
    protected string $alias = 'p';
    protected bool $distinct = false; // Enable DISTINCT for all repository queries
    protected ?array $orderBy = ['created_at DESC', 'id DESC']; // Default sorting

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
$affected = $repository->update('1', [
    'name' => 'Updated Name'
]);

// Update multiple records
$affected = $repository->update(['1', '2', '3'], [
    'enabled' => 0
]);
```

### Deleting Records

```php
$affected = $repository->delete('1');
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