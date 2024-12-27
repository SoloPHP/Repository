# Base Repository Class

A flexible base repository class for PHP 8+ with query builder and CRUD operations, featuring immutable query parameters.

## Installation

```bash
composer require solophp/repository
```

The package will automatically install required dependencies, including [solophp/database](https://github.com/solophp/database).

## Features

- Clean implementation of Repository pattern with immutability
- Fluent QueryBuilder interface
- Separation of concerns between Query Parameters and Builder
- Type-safe and IDE-friendly
- Automatic field sanitization
- Advanced filtering system:
    - Support for all database types (string, integer, float, array, date)
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
    public function filter(?array $filters): self;

    // Set the order of results
    public function orderBy(?string ...$order): self;

    // Set the page number for pagination
    public function page(int|string|null $page): self;

    // Set the number of items per page
    public function perPage(int|string|null $perPage): self;

    // Set primary key for result indexing
    public function primaryKey(string $primaryKey): self;
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
            'id' => 'AND p.id IN(?a)',
            'enabled' => 'AND p.enabled = ?i',
            'category_id' => 'AND c.id IN(?a)',
            'keyword' => fn($value) => $this->db->prepare("AND p.name LIKE '%?s%'", $value)
        ];
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
    ->filter([
        'enabled' => 1,
        'category_id' => [1, 2, 3]
    ])
    ->read();

// Read with pagination (accepts both int and string)
$products = $repository
    ->page(2)        // or ->page('2')
    ->perPage(20)    // or ->perPage('20')
    ->read();

// Read with sorting
$products = $repository
    ->orderBy('name', 'created_at DESC')
    ->read();

// Read one record
$product = $repository
    ->filter(['id' => 1])
    ->readOne();

// Read all records
$products = $repository->readAll();

// Count records
$count = $repository
    ->filter(['enabled' => 1])
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

Example:
```php
protected function filters(): array
{
    return [
        'id' => 'AND p.id IN(?a)',                    // Array of IDs
        'enabled' => 'AND p.enabled = ?i',            // Integer value
        'price' => 'AND p.price > ?f',                // Float value
        'name' => 'AND p.name = ?s',                  // String value
        'created_at' => 'AND p.created_at > ?d',      // Date value
        'search' => 'AND p.name LIKE ?l',             // LIKE search
        'status' => 'AND p.status IN(?a)',            // Array of strings
        'table' => 'AND ?t.column = ?i',              // Table name with prefix
        'raw' => 'AND ?p',                            // Raw SQL
        'data' => 'AND p.data = ?A',                  // Associative array
        // Custom complex filter
        'keyword' => fn($value) => $this->db->prepare(
            "AND (p.name LIKE ?l OR p.sku LIKE ?l)", 
            $value, 
            $value
        )
    ];
}
```

## Requirements

- PHP ^8.2
- PDO extension
- solophp/database ^2.5

## License

MIT