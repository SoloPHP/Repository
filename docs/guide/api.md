# Public API reference

This reference describes Solo Repository 1.0: PHP 8.3+, Doctrine DBAL `^4.3`, and the `Solo\Repository` namespace. Relation definitions live in `Solo\Repository\Relations`. See the [design and integration guide](details.md) and `examples/definitions.php` in the source checkout.

`Repository<T>`, `Mapping<T>` and `ReadQuery<T>` use PHPDoc generics, where `T` is an object. `T` is covariant, so `Repository<Product>` is accepted where `Repository<object>` is expected. Relation definitions are generic over their owner and target models, so static analysis checks every `assign` callback against the target mapping. The runtime checks that hydration and relation assignment return objects; it does not enforce their concrete class. `Mapping`, `ReadQuery`, the configuration objects and the write helpers are `final readonly`; `Repository` is `readonly` and may be extended into a [typed repository](#typed-repositories). Readonly configuration does not make the shared DBAL connection, returned models or objects captured by callbacks immutable.

The public API consists of the types and methods documented below. The `Internal` namespace and members marked `@internal` are implementation details without a semantic-versioning compatibility promise: the constructors of `ReadQuery`, `LinkSet` and `TranslationStore` (obtain them from a `Repository`), the properties of `Filter`, and `Relations\Relation::resolve()`. The abstract `Relation` base is not an extension point: use the four supplied final definitions. `Repository` may be extended under the compatibility rules of [typed repositories](#typed-repositories). The legacy `BaseRepository`, `RepositoryInterface` and singular `Relation` namespace belong to the separate `solophp/base-repository` package; see the [migration guide](migration.md).

## A minimal repository

The application supplies a DBAL connection and owns its schema and migrations. For a `products` table with an integer identity primary key, `sku` string and `price` decimal:

```php
use Doctrine\DBAL\Types\Types;
use Solo\Repository\Mapping;
use Solo\Repository\Repository;

final readonly class Product
{
    public function __construct(
        public int $id,
        public string $sku,
        public string $price,
    ) {
    }
}

// $db is the application's Doctrine\DBAL\Connection.
$products = new Repository(
    connection: $db,
    mapping: new Mapping(
        table: 'products',
        columns: [
            'id' => Types::INTEGER,
            'sku' => Types::STRING,
            'price' => Types::DECIMAL,
        ],
        hydrate: static fn(array $row): Product => new Product(
            $row['id'],
            $row['sku'],
            $row['price'],
        ),
    ),
);

$id = $products->insertGetId(['sku' => 'TABLE-01', 'price' => '199.90']);
$product = $products->read()->find($id); // Product|null
$products->update($id, ['price' => '179.90']); // affected rows; no model reread
```

## Typed repositories

An application may extend `Repository` with a `readonly` class that fixes the mapping in its own constructor. Each table then has a class of its own: a container can inject it by type, static analysis knows its model from `@extends`, and queries about that table can live beside it. The public methods of `Repository` are final, so a subclass adds a constructor and methods of its own and cannot change what the inherited ones do; the repository's state stays private to it.

```php
use Doctrine\DBAL\Connection;

/** @extends Repository<Product> */
final readonly class ProductRepository extends Repository
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection, self::mapping());
    }

    /** @return Mapping<Product> */
    public static function mapping(): Mapping
    {
        return new Mapping(table: 'products', columns: [/* … */], hydrate: static fn(array $row): Product => /* … */);
    }

    /** @return list<Product> */
    public function cheaperThan(string $price): array
    {
        return $this->read()->findBy(['price <' => $price], ['price' => 'ASC']);
    }
}

$product = (new ProductRepository($db))->read()->find($id); // Product|null
```

A `readonly` class can only be extended by a `readonly` class. A static `mapping()` also gives relations a target factory: `target: ProductRepository::mapping(...)`.

Subclasses can rely on these rules throughout 1.x:

- `Repository` stays a non-final `readonly` class. Its public methods, except the constructor, stay final, and its state stays private, so a subclass depends only on the public API.
- A minor release may add public methods to `Repository`. PHP refuses to load a subclass that declares a method of the same name, so name a subclass's methods after its table's domain, such as `cheaperThan()`, and read the changelog before upgrading.
- The constructor keeps the names, order and meaning of its parameters. A parameter added in a minor release is optional and comes last.

## Mapping and types

```php
Mapping::__construct(
    string $table,
    array $columns,                 // array<string, string>: column => DBAL type name
    ?callable $hydrate = null,     // callable(array<string, mixed>): T; NULL reads rows only
    ?string $primaryKey = 'id',    // NULL for a table without a single-column key
    bool $generatedId = true,
    ?string $softDeleteColumn = null,
    ?Translations $translations = null,
    array $relations = [],         // array<string, Relations\Relation>
);

Mapping::columnType(string $column): string;
Mapping::fieldType(string $field): string;
Mapping::readColumns(): array;      // array<string, string>
Mapping::relation(string $name): Relations\Relation;
```

All constructor settings are public readonly properties; `hydrate` is stored as a `Closure`, or NULL. A mapping without a hydrator, such as one for a link table that no model represents, is read as rows: `rows()`, aggregates, `queryBuilder()`, related filters and writes work, while `find()`, `findOneBy()`, `findBy()`, `iterateById()` and loading it as a relation target throw `LogicException` before SQL. `columnType()` accepts only base columns. `fieldType()` accepts base or translated fields. `readColumns()` returns their combined type map, and `relation()` looks up a configured relation. Unknown names throw `InvalidArgumentException`.

Names in `columns`, criteria, projections, sorting and write data are database column names. A hydrator can convert them to camelCase properties or invoke any application constructor. Names form an allowlist, not SQL expressions. Table names, column names and relation names must match `[A-Za-z_][A-Za-z0-9_]*`; schema-qualified tables are not supported. Identifiers are quoted through DBAL and values are bound separately.

Every base type must be registered with DBAL. Read values are converted through the mapped DBAL type before hydration; `rows()` applies the same conversion. For example, decimal values remain strings, JSON becomes a PHP value, and `datetime_immutable` becomes `DateTimeImmutable`. On writes and comparisons, pass values accepted by that DBAL type. Register custom types in DBAL; types requiring custom SQL conversion wrappers are outside this API.

The single primary key must be present in `columns` and use one of:

| PK types | `generatedId: true` | ID handling |
|---|---|---|
| `smallint`, `integer`, `bigint` | Allowed | `int` or canonical decimal string |
| `string`, `ascii_string`, `guid` | Rejected; set `false` | Nonempty string; an integer ID becomes a string |

Methods with a separate ID argument and explicit primary keys in insert data normalize that ID before SQL. Integer strings must match `0` or an optionally negative decimal integer without leading zeros: `12` and `-12` are valid; `01`, `+1`, `-0`, `1.5`, whitespace and `12x` are invalid. `integer`/`smallint` IDs outside the PHP integer range are rejected. `bigint` conversion follows DBAL and may return a string outside that range. Database column ranges and UUID syntax remain database/type concerns.

Set `primaryKey: null` for a table without a single-column key, such as one with a composite key. Its rows are read, inserted and changed through criteria: `findBy()`, `rows()`, `count()`, the aggregates, `queryBuilder()`, `insert()`, `insertMany()`, `updateBy()`, `deleteBy()` and `purgeBy()`. Operations that address one row by ID (`find()`, `insertGetId()`, `update()`, `delete()`, `restore()`, `purge()` and `lockForUpdate()`) throw `LogicException` before SQL, and `iterateById()` throws `InvalidArgumentException`. Such a mapping has no generated ID, and every inserted row needs at least one column.

A mapping without a primary key cannot have translations, and its only relations are `BelongsTo`: `HasOne`, `HasMany` and `BelongsToMany` match their owner by its primary key. It can be the target of `HasOne` and `HasMany`, but not of `BelongsTo` or `BelongsToMany`, which reference the target's primary key. Its `BelongsTo` relations can be filtered by with any key type; loading one needs an integer target key, because string keys follow the database collation and are matched in SQL through the owner's primary key. Loading a string-key `BelongsTo` from such an owner throws `LogicException` before SQL.

Criteria values of integer fields follow the same rule without being converted: `findBy(['id' => '12x'])` throws instead of matching 12. See [Criteria and Filter](#criteria-and-filter) for the other field types.

`softDeleteColumn`, when configured, must be a base column using `datetime`, `datetime_immutable`, `datetimetz` or `datetimetz_immutable`. The physical column must allow NULL. Translated fields must not overlap with base columns. A `BelongsTo` owner FK must already be a base column; target-side relation metadata is checked when the relation is resolved.

## Repository and read queries

```php
Repository::__construct(Connection $connection, Mapping $mapping, ?int $parameterLimit = null);
Repository::read(): ReadQuery;                          // ReadQuery<T>
Repository::withLocale(string $locale, ?string $fallback = null): ReadQuery;
Repository::with(array $relations): ReadQuery;          // list<string|Load>
Repository::translations(): TranslationStore;
Repository::links(string $relation): LinkSet;
```

`withLocale()` and `with()` are shorthands for `read()->withLocale()` and `read()->with()`. Use `read()->withVisibility()` to change root visibility. `translations()` requires translation metadata; `links()` requires a `BelongsToMany` definition and resolves its target mapping immediately. `parameterLimit` overrides the [parameter budget](#criteria-and-filter).

Constructing repositories, queries, link sets and translation stores never opens a connection: the platform is resolved when the first statement is built. Queries are obtained from a repository:

```php
ReadQuery::withLocale(string $locale, ?string $fallback = null): self;
ReadQuery::with(array $relations): self;
ReadQuery::withVisibility(Visibility $visibility): self;
ReadQuery::forUpdate(): self;

ReadQuery::find(int|string $id): ?object;               // T|null
ReadQuery::findOneBy(array|Filter $criteria, array $orderBy = []): ?object;
ReadQuery::findBy(
    array|Filter $criteria = [],
    array $orderBy = [],
    ?int $limit = null,
    int $offset = 0,
): array;                                             // list<T>
ReadQuery::count(array|Filter $criteria = []): int;
ReadQuery::exists(array|Filter $criteria = []): bool;
ReadQuery::sum(string $field, array|Filter $criteria = []): mixed;
ReadQuery::min(string $field, array|Filter $criteria = []): mixed;
ReadQuery::max(string $field, array|Filter $criteria = []): mixed;
ReadQuery::avg(string $field, array|Filter $criteria = []): ?float;
ReadQuery::rows(
    array $columns,                                   // nonempty list<string>
    array|Filter $criteria = [],
    array $orderBy = [],
    ?int $limit = null,
    int $offset = 0,
): array;                                             // list<array<string, mixed>>
ReadQuery::iterateById(array|Filter $criteria = [], int $batchSize = 500): iterable;
ReadQuery::queryBuilder(string $alias, array|Filter $criteria = []): QueryBuilder;
```

`criteria` is an associative array or `Filter`. `orderBy` maps readable fields to `ASC`/`DESC`, case-insensitively. All `with*()` methods and `forUpdate()` return a new query; executing a query does not consume its settings. `with()` replaces the complete include list, and `with([])` clears it.

In `findBy()`, `findOneBy()` and `rows()`, an `orderBy` key may also be a dotted path through `BelongsTo` relations to a readable field of their target, such as `category.name` or `category.parent.name`. Each target on a path is LEFT JOINed once per statement on its primary key, over the rows that a load of the same relation path in the read sees: its active rows, unless the read loads that relation with another visibility. A list that shows soft-deleted categories through `with([new Load('category', visibility: Visibility::All)])` thus also orders by their names; only the load's visibility applies, not its criteria. A row whose reference is NULL, dangling or not visible orders as one with NULL there; where NULL sorts follows the database (first in ascending order on MySQL, MariaDB and SQLite, last on PostgreSQL). A translated target field is read in the query locale and fallback, which the target inherits as relation loads do. Paths through `HasOne`, `HasMany` or `BelongsToMany`, which could match several rows, are rejected before SQL. The default order of a to-many relation and the order of a `Load` take the same paths, resolved from the target.

- `find()` and `findOneBy()` return NULL when no visible row matches. They limit the root SELECT to one row.
- `findBy()` materializes the requested models and their includes. No automatic pagination count is performed.
- `rows()` returns only the selected, DBAL-converted fields and never calls the hydrator. It rejects nonempty includes; use `with([])` on that branch.
- `count()` and `exists()` do not hydrate models or resolve/load includes. Relation predicates in their criteria still apply. `exists()` selects a constant with a one-row limit.
- `sum()`, `min()`, `max()` and `avg()` aggregate one readable field, including a translated field under the query locale, over the rows `count()` would count. `sum()`, `min()` and `max()` convert the result through the field's DBAL type, so a decimal sum is an exact string and `max('created_at')` a `DateTimeImmutable`. `sum()` returns zero of that type when nothing matches; `min()`, `max()` and `avg()` return NULL. `avg()` returns a float. `sum()` and `avg()` require a smallint, integer, bigint, decimal, float or smallfloat field and reject other fields before SQL.
- `queryBuilder()` returns a new DBAL `QueryBuilder` over the rows `count()` would count, for SQL the other methods cannot express; see [the DBAL escape hatch](#transactions-and-the-dbal-escape-hatch). It rejects nonempty includes.
- `forUpdate()` returns the same read as a locking one: `find()`, `findOneBy()`, `findBy()`, `rows()`, `exists()` and every batch of `iterateById()` run `SELECT ... FOR UPDATE` and lock the rows they read until the surrounding transaction ends; outside a transaction they throw `LogicException`. Unlike a plain read in a REPEATABLE READ transaction, which answers from the transaction's snapshot, a locking read never returns an older version of a row: MySQL reads the latest committed row, while PostgreSQL and MariaDB (with `innodb_snapshot_isolation`, its default since 11.6) reject a row changed since the snapshot with a serialization failure, after which the transaction must start over. The lock covers rows of the read's own table: PostgreSQL and MySQL 8 lock with `FOR UPDATE OF`, while MariaDB, which lacks it, also locks the translation and order rows the statement joins. Relation loads read without locks. Aggregates and `queryBuilder()` of a locking read throw `LogicException`. SQLite has no row locks and runs the plain SELECT. Rows lock in the order the database reads them; where transactions lock overlapping sets of rows, read them in key order or take `lockForUpdate()` first. MySQL and MariaDB lock every row they read to answer the statement, so with an order no index serves, such as a relation path, a limited locking read locks all matching rows, not just the page.
- Without `orderBy`, ordering is unspecified. Add the primary key as a final tie-breaker for stable pages. Negative limit/offset values are rejected. `limit: 0` validates query settings and returns an empty list without executing the read.

```php
$page = $products->read()->findBy(
    criteria: ['price >=' => '50.00'],
    orderBy: ['price' => 'ASC', 'id' => 'ASC'],
    limit: 20,
    offset: 40,
);
$rows = $products->read()->rows(['id', 'sku'], orderBy: ['id' => 'ASC']);
```

`iterateById()` yields `T` in ascending primary-key order using bounded queries with `id > lastId`. It requires an integer-family PK and a positive batch size. Validation happens when the method is called; read SQL begins when iteration starts. Locale, visibility, filters and includes apply to every batch. Continuation uses database row keys, so models need not expose an ID property.

This is neither a server cursor nor a snapshot. Use application transaction/isolation rules for a consistent export. Memory holds the current batch and its related records; a large relation collection can still be large. The library releases completed batches, but a consumer can retain objects itself. Arbitrary ordering, composite cursors and streaming raw projections require DBAL.

## Criteria and Filter

An array combines its entries with AND. A bare field name means equality:

```php
['status' => 'active', 'price >=' => '50.00', 'id IN' => [1, 2, 3]]
```

Supported operators are `=`, `!=`, `>`, `>=`, `<`, `<=`, `IN`, `NOT IN`, `LIKE`, `NOT LIKE`. Operator spelling is case-insensitive. `= null` and `!= null` become `IS NULL` and `IS NOT NULL`. NULL with other operators is rejected. Arrays require explicit `IN`/`NOT IN`; their elements cannot be NULL or arrays. `IN []` is false, `NOT IN []` is true, and an empty criteria array is true. A list is bound as one DBAL list parameter of values converted through the field type, which DBAL expands into one placeholder per value when the statement runs; a type without a DBAL list binding, such as boolean, binds each value separately.

A key holds both the field and the operator, so build criteria keys only from fixed strings in your code, never from request parameters or other external input. Whoever controls a key chooses its operator: `filter[price >=]=0` would turn an equality into a comparison. When users choose the fields to filter by, map each allowed name to a fixed key:

```php
$keys = ['category' => 'category_id', 'min_price' => 'price >=', 'max_price' => 'price <='];
$criteria = [];
foreach (array_intersect_key($request, $keys) as $name => $value) {
    $criteria[$keys[$name]] = $value;
}
```

Every value, including each IN element, must have a form of its field type before SQL; a driver would otherwise reinterpret it silently, turning `'12x'` into 12 or, on MySQL, `'false'` into TRUE. Integer fields take `int` or a canonical decimal string. Decimal fields take finite numbers or plain decimal strings such as `'-12.50'`, without an exponent. Float fields take finite numbers or numeric strings with a finite value. Boolean fields take `bool`, `0`, `1`, `'0'` or `'1'`. String, text and GUID fields take strings, integers or `Stringable` objects; enum fields take strings or `Stringable` objects, because MySQL reads an integer as a member index. `simple_array` fields take arrays. A LIKE pattern therefore needs a string field. DBAL itself rejects mistyped dates, intervals and bcmath numbers; JSON, binary and custom types receive their values unchanged. The check covers the form of a value, not the column's range, precision or scale, which stay database constraints: SQLite keeps decimals as REAL, so a decimal beyond that range becomes infinity there.

```php
Filter::all(array|Filter ...$parts): Filter;
Filter::any(array|Filter ...$parts): Filter;
Filter::not(array|Filter $part): Filter;
Filter::related(
    string $relation,
    array|Filter $criteria = [],
    Visibility $visibility = Visibility::Active,
): Filter;
```

`all()` and `any()` require at least one argument. `Filter` is created through these factories; its constructor is private and its properties are internal to the compiler. All branches are validated, including those eliminated by constant folding. SQL NULL logic still applies: `not(['price >' => '10.00'])` does not automatically include NULL prices.

`related()` means EXISTS and accepts a single relation name. Conditions inside one call must match the same related row. Separate calls can match different rows. Use nested calls for deeper relationships and `not(related(...))` for NOT EXISTS:

```php
use Solo\Repository\Filter;

$criteria = Filter::all(
    Filter::related('tags', ['slug' => 'sale', 'enabled' => true]),
    Filter::not(Filter::related('tags', ['slug' => 'blocked'])),
    Filter::related('category', Filter::related('parent', ['id' => 10])),
);
```

Related predicates filter owners without duplicating their rows. They do not restrict what `with()` loads. Field paths such as `tags.slug`, raw SQL, implicit scopes and collection sorting are not accepted. LIKE wildcards, case sensitivity, collation and NULL ordering follow the database.

Every statement has a budget of bound parameters, including locale JOINs and nested EXISTS, in which every value of an IN list counts. By default it is the platform limit: 65,535 for PostgreSQL and MySQL/MariaDB, 32,766 for SQLite 3.32 or later, and 900 for older SQLite and other platforms. `new Repository($db, $mapping, parameterLimit: 2000)` replaces it for that repository, its queries and its relation loads, for example to reject large root IN filters early. The value must be positive and must not exceed what the server accepts; raise it only on a platform whose real limit is above the default. Keyset iteration reserves one parameter for continuation. Oversized root criteria are rejected rather than split: splitting would change ordering, pagination or Boolean semantics. Loader batches, `insertMany()` and `LinkSet` split their own internal work within this budget; an operation whose single row does not fit, such as a two-parameter pivot row under a budget of 1, throws InvalidArgumentException. Internal key lists (relation loads, `lockForUpdate()` and `LinkSet` deletes) also hold at most 5,000 keys per statement, because longer IN lists can exceed MySQL's range-optimizer memory and turn index lookups into full scans.

## Translation metadata and locale

```php
Translations::__construct(
    string $table,
    string $foreignKey,
    array $columns,                 // nonempty array<string, string>
    ?string $defaultLocale = null,
    string $localeColumn = 'locale',
);
```

All settings are public readonly properties. Translated columns support only `Types::STRING` and `Types::TEXT`, must be nullable in the database, and cannot use the foreign-key or locale-column name. The foreign-key and locale-column names must differ. Example configuration:

```php
use Solo\Repository\Translations;

$translations = new Translations(
    table: 'product_translations',
    foreignKey: 'product_id',
    columns: ['name' => Types::STRING, 'description' => Types::TEXT],
    defaultLocale: 'en',
);
// Pass translations: $translations when creating Mapping.
```

The schema must enforce a foreign key to the owner's PK and UNIQUE `(foreignKey, localeColumn)`. Matching PK/FK types and collation are the application's responsibility. That pair must be the only possible uniqueness conflict when inserting translation data: MySQL's native upsert can react to any unique index, so extra UNIQUE constraints on translated fields require a separate application write operation. Mapping does not inspect or create the schema.

Ordinary model reads use `defaultLocale` with no fallback. Without a default locale, a read that names no locale joins no translations: translated fields come back NULL, and conditions, sorts and aggregates on them throw a `LogicException` until the read calls `withLocale()`. `withLocale('uk', fallback: 'en')` resolves each field independently as `COALESCE(uk.field, en.field)`. A missing row or NULL field can fall back; `''` and `'0'` remain valid translations. SELECT, WHERE, ORDER BY and related predicates use the same resolved expression.

Locale/fallback values must be nonempty after PHP `trim()`; their original text is otherwise preserved. The package does not canonicalize language tags or infer regional fallback. A fallback equal to the locale is redundant and removed. Calling `withLocale('en')` clears a previously configured fallback; the direct constructor rejects a fallback without a locale.

The effective locale and fallback are inherited by related reads and predicates, even through a non-translated entity. If the root has neither an explicit nor a default locale, the first translated entity with a default locale in each branch chooses it and passes it onward; translated entities above it read NULL translated fields. There is no per-include locale override. Translation JOINs are only added when fields used by a projection, condition or sort need them; full translated models include all translated fields.

## TranslationStore

Obtain the store from `$repository->translations()`:

```php
TranslationStore::get(int|string $id, string $locale): ?array; // array<string, string|null>|null
TranslationStore::all(int|string $id): array; // array<string, array<string, string|null>> by locale
TranslationStore::save(int|string $id, string $locale, array $patch): void;
TranslationStore::seed(int|string $id, array $locales, array $values): int; // list<string> locales
TranslationStore::delete(int|string $id, string $locale): int;
```

`get()` reads only the requested locale, without fallback, and returns all configured translated fields. NULL means no translation row; an existing row whose fields are all NULL returns an array. `all()` returns every stored locale of one owner with the same fields, keyed by locale in ascending order, or an empty array. `save()` accepts `array<string, string|null>` restricted to translated fields:

- Existing row: update only fields present in the patch.
- New row: omitted configured fields take their column defaults. A column without a default gets NULL, which leaves the field to the fallback locale when reading.
- Empty patch: validate ID and locale, then execute no SQL.

Each nonempty save performs one native upsert, without reading or hydrating the owner. It is implemented for PostgreSQL/SQLite with `ON CONFLICT`, and MySQL/MariaDB with `ON DUPLICATE KEY UPDATE`. Other platforms are rejected.

`seed()` fills locales that have no translation yet, for example every active locale of a new entity, and returns the number of rows inserted. Existing rows are left unchanged, so seeding can be repeated. Values follow the `save()` rules, and omitted fields of a new row take their column defaults. Duplicate locales collapse; an empty locale list or empty values execute no SQL. Only a conflict on the `(foreignKey, localeColumn)` pair is skipped: `ON CONFLICT ... DO NOTHING` on PostgreSQL/SQLite and a no-op `ON DUPLICATE KEY UPDATE` on MySQL/MariaDB, which reports existing rows as unchanged unless the connection enables `CLIENT_FOUND_ROWS`. Seeds larger than the parameter budget are split inside one transaction.

`delete()` removes one owner/locale pair and returns affected rows. These operations address physical owners, including soft-deleted owners, and participate in the caller's connection transaction. Database constraint errors propagate.

## Relations and Load

Import the four concrete definitions from `Solo\Repository\Relations`:

```php
BelongsTo::__construct(callable $target, string $foreignKey, ?callable $assign = null);
HasOne::__construct(callable $target, string $foreignKey, ?callable $assign = null);
HasMany::__construct(callable $target, string $foreignKey, ?callable $assign = null, array $orderBy = []);
BelongsToMany::__construct(
    callable $target,
    string $pivotTable,
    string $foreignPivotKey,
    string $relatedPivotKey,
    ?callable $assign = null,
    array $orderBy = [],
);
```

Names/keys are public readonly properties. `target` and `assign` are public readonly closures; `assign` is NULL for a relation that is only filtered by (`Filter::related()`) or linked (`links()`), and loading such a relation throws `LogicException` before SQL. The `orderBy` of a to-many definition is the default order of every load of that collection, normalized like `Load::orderBy`; a `Load` with its own non-empty order replaces it. The zero-argument `target` factory returns the target's `Mapping`; it is called lazily, on first use, so mappings may reference each other. Its result is kept for each owner mapping, so later loads, filters and link sets reuse it, and a factory may build the target `Mapping` itself. Related rows are read through the owner repository's connection and parameter budget. Related keys reference primary keys:

| Definition | Foreign-key location | `assign` signature |
|---|---|---|
| `BelongsTo<TOwner, TTarget>` | Owner's base columns | `callable(TOwner, ?TTarget): TOwner` |
| `HasOne<TOwner, TTarget>` | Target's base columns; enforce UNIQUE | `callable(TOwner, ?TTarget): TOwner` |
| `HasMany<TOwner, TTarget>` | Target's base columns | `callable(TOwner, list<TTarget>): TOwner` |
| `BelongsToMany<TOwner, TTarget>` | Pivot with two distinct FK columns | `callable(TOwner, list<TTarget>): TOwner` |

```php
use Solo\Repository\Relations\BelongsTo;

$relation = new BelongsTo(
    target: static fn(): Mapping => $categories,          // Mapping<Category>
    foreignKey: 'category_id',
    assign: static fn(Product $product, ?Category $category): Product => $product->withCategory($category),
);
```

Direct FK/PK type compatibility is checked within the integer family or within the string/ASCII/GUID family. Translated fields cannot be FKs. Pivot owner and target PK types may differ; each pivot FK must match its own referenced PK. Both pivot FKs and UNIQUE `(foreignPivotKey, relatedPivotKey)` are required. Additional pivot attributes need their own repository.

`assign(owner, related): object` must return the owner after mutation or a replacement immutable owner. The returned object is used for subsequent assignments and the final result. Nested includes are assigned before their parent. Missing visible to-one targets produce NULL; empty to-many targets produce `[]`. Unloaded relations retain the state established by the hydrator. A `HasOne` load matching multiple target rows throws instead of choosing one.

```php
Load::__construct(
    string $name,                   // relation name or dotted include path
    array|Filter $criteria = [],
    array $orderBy = [],
    Visibility $visibility = Visibility::Active,
);
```

`Load` properties are public readonly; sort directions are normalized to uppercase. `with()` takes a list of strings or Load objects:

```php
use Solo\Repository\Load;
use Solo\Repository\Visibility;

$query = $products->with([
    new Load('category', visibility: Visibility::All),
    'category.parent',
    new Load('tags', criteria: ['enabled' => true], orderBy: ['name' => 'ASC', 'id' => 'ASC']),
]);
```

A string creates the required path; a `Load` configures its final edge. In this example, `category.parent` retains Active visibility. Duplicate paths are merged; conflicting explicit settings for the same exact path are rejected. Criteria values are compared strictly, so NULL and `''` are different settings. Unknown relations and invalid target fields are checked when preparing model reads, including a read with `limit: 0`.

Load criteria limit related results, not owners. Root pagination happens before collection loading. For each owner batch, a direct relation uses one SELECT and a many-to-many relation uses a pivot SELECT plus a target SELECT. Translation JOINs add no separate query. Large owner sets need more batches. Integer keys are matched by value: a `BelongsTo` target shared by many owners is selected, hydrated and assigned as one object. String keys are matched in SQL through the owner table, so equality follows the database collation; each distinct target is still hydrated once. `Load.orderBy` orders each collection using the database; absent ordering is unspecified. There is no per-parent limit, lazy loading, identity map or automatic graph persistence.

## Visibility and soft deletion

`Visibility` is an enum with `Active`, `All` and `Deleted` cases:

| Visibility | Configured soft-delete column | No soft-delete column |
|---|---|---|
| `Active` | `IS NULL` | All rows |
| `All` | No visibility predicate | All rows |
| `Deleted` | `IS NOT NULL` | InvalidArgumentException |

Visibility is applied outside the user's Boolean group. A condition on `deleted_at` does not disable it. `withVisibility()` affects only the root; includes and `Filter::related()` default to Active independently. Override each with `Load(..., visibility: ...)` or `Filter::related(..., visibility: ...)`. Translation rows have no separate visibility policy.

## Repository writes

```php
Repository::insert(array $data): int;
Repository::insertGetId(array $data): int|string;
Repository::insertMany(array $rows, int $batchSize = 500): int;
Repository::upsert(array $key, array $data, array $onInsert = []): void;
Repository::update(int|string $id, array $patch): int;
Repository::updateBy(array|Filter $criteria, array $patch): int;
Repository::delete(int|string $id): int;
Repository::deleteBy(array|Filter $criteria): int;
Repository::restore(int|string $id): int;
Repository::purge(int|string $id): int;
Repository::purgeBy(array|Filter $criteria): int;
Repository::lockForUpdate(int|string|array $ids): void;     // list<int|string> when an array
```

Data/patch arrays use base column names. Translated fields and unknown columns are rejected. Written values follow the type rules of criteria values (see [Criteria and Filter](#criteria-and-filter)) before any SQL, so `update($id, ['quantity' => 'abc'])` throws instead of storing 0; NULL passes through to the database. Writes do not hydrate or reread models. Every method returning `int`, except `insertGetId()`, returns affected rows. The database/driver controls affected-row semantics: zero from UPDATE does not prove that the row is absent.

Both `insert()` and `insertGetId()` perform an INSERT; do not call both for the same record. `generatedId: false` requires an explicit PK. With generated IDs, omit the PK to use the database identity, or supply one permitted by the schema. Empty insert data uses the database's empty identity INSERT syntax/defaults. `insertGetId()` returns the explicit normalized ID or retrieves a generated ID using PostgreSQL `RETURNING` or MySQL/SQLite driver support. No generated-ID strategy is promised for other platforms or database-generated UUIDs.

Explicit inserts do not override `GENERATED ALWAYS` or advance a PostgreSQL sequence to match a manually assigned ID. MySQL rejects explicit zero when `generatedId: true`, since server settings may reinterpret it as an identity request. Explicit GUID/string IDs are normalized by mapped type, not reread to discover a database-canonical spelling.

Patch semantics preserve omitted fields and write SQL NULL for explicit NULL. Patches cannot change the primary key or soft-delete column. An empty patch returns zero without SQL after validating its criteria. `updateBy()` and `deleteBy()` accept only base-column criteria and Boolean groups; translated fields and related predicates are rejected. Empty filters and filters folded to true are rejected before visibility is added. This is a guard against empty filters, not a proof that an arbitrary expression selects only a small subset; use DBAL for intentional all-row writes.

With soft deletion, update methods affect only active rows. Delete methods set a UTC timestamp only on active rows; deleting an already deleted row returns zero without replacing its timestamp. `restore()` clears a deleted row's timestamp and requires soft-delete metadata. `purge()` and `purgeBy()` always delete physically, regardless of visibility; `purgeBy()` rejects empty criteria like `deleteBy()`. Without soft deletion, delete methods delete physically. Database FK rules determine cleanup of translations and pivot rows.

`lockForUpdate()` locks the physical rows with the given primary keys until the surrounding transaction ends, regardless of visibility, and rejects calls outside a transaction. IDs are normalized and deduplicated, and rows are locked in key order, which keeps concurrent lockers from deadlocking. Large key sets are split into statements of at most 5,000 keys. Integer keys are sorted before splitting, so the order holds across statements; string keys follow the database collation within each statement. It uses the platform's `SELECT ... FOR UPDATE`; SQLite has no row locks and runs the plain SELECT. Cross-process advisory locks, such as one critical section per order, stay in the application.

`insertMany()` takes a list of rows with identical column sets; key order may differ. It validates row structure before writing and splits by both `batchSize` and the parameter budget. A batch that fits one statement runs as that statement, which is atomic on its own; a split batch wraps all its chunks in one transaction/savepoint, so a later chunk failure rolls back earlier chunks. Like a failed `insert()`, a one-statement batch that fails inside a caller's transaction on PostgreSQL aborts that transaction; only a split batch rolls back to its own savepoint. An empty list returns zero; batch size must still be positive. It returns affected rows, not generated IDs.

`upsert()` writes the row of a unique key in one native statement: `ON CONFLICT` on PostgreSQL and SQLite, `ON DUPLICATE KEY UPDATE` on MySQL and MariaDB. It inserts `key + data + onInsert` as a new row or, when a row already holds the key, updates that row's `data` columns and leaves its key and other columns as they are. `onInsert` holds what only a new row gets, such as an explicit ID or a creation time; with empty `data` an existing row stays unchanged. The key must be nonempty, hold no NULL and name the columns of one unique index or of the primary key: PostgreSQL and SQLite use them as the conflict target and reject a key without such an index, while MySQL and MariaDB update the row on a conflict over any unique index of the table and insert another row when none covers the key. Each column appears once across the three arrays; `data` cannot change the primary key, and none of them the soft-delete column. The new row is validated like `insert()`, so a mapping without generated IDs needs the ID in `key` or `onInsert`. The row holding the key is updated even when it is soft-deleted, and stays deleted; `restore()` it explicitly. Affected-row counts of upserts differ between platforms, so `upsert()` returns nothing: read the row back by its key.

## LinkSet

Obtain link sets from `$repository->links('tags')`:

```php
LinkSet::attach(int|string $id, array $ids): void; // list<int|string> target IDs
LinkSet::detach(int|string $id, array $ids): void;
LinkSet::sync(int|string $id, array $ids): void;
```

The relation must be `BelongsToMany`. Owner and target IDs are normalized using their respective mapped PK types; target IDs are deduplicated. Input order does not define relation order.

- `attach()` adds missing links; existing links are unchanged.
- `detach()` removes the specified links; absent links are unchanged.
- `sync()` makes the physical set exactly match the supplied IDs; `sync($id, [])` removes all links for that owner.

Empty attach/detach lists execute no SQL after ID validation. Existing-link attach is a no-op in state, but can perform reads. These operations use physical pivot rows, including soft-deleted owners/targets; read visibility and Load criteria do not apply. String keys are resolved through SQL so comparisons respect the database's collation. Foreign-key and unique violations propagate, and orphan cleanup for a schema without the required FKs is not promised.

All nonempty attach/detach operations and every sync use a transaction/savepoint and split large statements. This provides rollback, not serialization of concurrent edits to the same set. Coordinate concurrent writers with an application lock or transaction strategy. No automatic retries or blanket `INSERT IGNORE` are used.

## Transactions and the DBAL escape hatch

Keep the application's `Connection` available. Use it for transaction boundaries spanning repositories, translation stores and link sets. This example assumes translations and a `tags` many-to-many relation are configured:

```php
$db->transactional(static function () use ($products, $id): void {
    $products->update($id, ['sku' => 'TABLE-02']);
    $products->translations()->save($id, 'uk', ['name' => 'Стіл']);
    $products->links('tags')->sync($id, [2, 5]);
});
```

Single CRUD statements, upserts, translation upserts and an `insertMany()` that fits one statement do not add transactions. Composite operations use DBAL transactions, including nested savepoints in an outer transaction. The package does not close the application connection.

For joins, aggregates over several tables, advisory locks, raw projections, special types or other SQL features, use DBAL. To keep a mapping's criteria in such SQL, start from `ReadQuery::queryBuilder()`:

```php
$query = $stocks->read()->queryBuilder('s', ['store_id' => $storeId]);
$value = $query
    ->select('COALESCE(SUM(b.remaining * b.price), 0)')
    ->innerJoin('s', 'batches', 'b', 'b.product_id = s.product_id')
    ->andWhere('b.remaining > ' . $query->createNamedParameter(0, Types::INTEGER))
    ->fetchOne(); // a raw driver value
```

The builder has the mapped table under the alias in FROM, the criteria and the query's visibility in WHERE, and the translation joins the criteria need under the query locale. It selects nothing. Add your own conditions with `andWhere()`, never `where()`: DBAL's `where()` replaces the whole WHERE clause and silently drops the criteria and the soft-delete condition. Name the table by the alias, a lowercase SQL identifier, so that unquoted references such as `s.product_id` match it on every platform; the translation joins are named `<alias>_translation` and `<alias>_fallback`. Criteria values are bound as named parameters, so bind further values with `createNamedParameter()`. An IN list is one `ArrayParameterType` parameter that DBAL expands when it runs the statement: run the builder itself, or pass `getParameterTypes()` along with `getParameters()` when its SQL becomes part of another statement. Each call validates the criteria and returns a new builder; nothing runs until the caller executes it.

Everything the caller adds is plain DBAL: joined tables get no visibility or locale, and fetched rows are raw driver values, not converted through the mapping's types. Other DBAL queries, from `$db->createQueryBuilder()` or DBAL's execution methods, must apply any desired visibility, locale, parameter typing and result conversion explicitly.

## Errors and supported boundaries

| Failure | Expected category |
|---|---|
| Invalid metadata, unknown fields, unsupported filters/options, invalid IDs or parameter budget | `InvalidArgumentException` |
| Missing translation metadata, restore without soft deletion, unsupported generated-ID/upsert platform, locking read outside a transaction, aggregate or query builder of a locking read | `LogicException` |
| Non-object hydration/assignment result, invalid relation factory result, multiple HasOne matches, invalid generated-ID result | `UnexpectedValueException` |
| SQL, FK, UNIQUE or DBAL type-conversion failure | Original DBAL/driver exception |
| Application callback failure or incompatible PHP parameter/return declaration | Original exception or PHP `TypeError` |

Validation happens before package SQL where possible; target relations are lazy, and custom callbacks/types can fail during execution. Exception messages and internal implementation types are not a parsing API.

SQLite is covered by the main suite; MySQL 8.4, MariaDB 12.3 and PostgreSQL 17 are covered by the real-database matrix. MariaDB uses the MySQL code paths for generated IDs and upserts; its locking reads also lock the rows they join, since it has no `FOR UPDATE OF`. Other DBAL platforms are not supported. Migrations must supply correct PK/FK types and collation, nullable translation/soft-delete fields, translation/pivot uniqueness and HasOne uniqueness; the package does not introspect these guarantees. There are no composite primary keys, alternate-key relations, schema generation, caches, change tracking, cascaded object writes or model identity guarantees.
