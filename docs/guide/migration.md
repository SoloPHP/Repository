# Migrating from Base Repository v3

This guide moves an application from **`solophp/base-repository` v3** to
**`solophp/repository` 1.0**. Solo Repository is a separate package with the
`Solo\Repository` namespace.

The new package uses composition and does not contain `BaseRepository`,
`RepositoryInterface`, or the old singular `Relation` namespace. `Repository`
does not implement the old interface. The
[v3 documentation](https://github.com/SoloPHP/Base-Repository/tree/v3.1.0/docs)
remains with the original product.

The migration changes repository construction, query state, criteria, and write
results. Keep the existing schema initially, audit the constraints below, and
migrate one workflow at a time. The source checkout's `examples/migration.php`
runs a catalog query, translates and updates a product, replaces its tags in a
transaction, verifies rollback, and exports the result:

```sh
composer install
php examples/migration.php
```

## 1. Dependencies and installation

Solo Repository requires PHP `^8.3` and Doctrine DBAL `^4.3`. Update the
application's PHP runtime, PDO driver, and dependency constraints together.
Check other Doctrine dependencies when upgrading DBAL.

Add the new package using the [installation instructions](index.md#install).
If Composer reports a conflict with an existing dependency, review its constraint
and use `--with-all-dependencies` when updating the related packages.

Keep `solophp/base-repository:^3.0` installed while migrating its callers. The
namespaces are independent; the new package neither `replace`s nor `provide`s
the old one. Once no code uses the old API, remove its dependency:

```sh
composer remove solophp/base-repository
```

Change imports for new code to `Solo\Repository`. This is **not** a namespace-only
migration from v3: construction and behavior also change as described below.

The integration matrix covers MySQL 8.4 and PostgreSQL 17, alongside SQLite.
Other DBAL platforms are outside that matrix. See the
[database test instructions](details.md#проверка-на-mysql-и-postgresql).

## 2. Replace inheritance with composition

V3 configured behavior on a `BaseRepository` subclass, usually hydrating through `Product::fromArray()`:

```php
// Base Repository v3
use Doctrine\DBAL\Connection;
use Solo\BaseRepository\BaseRepository;

final class ProductRepository extends BaseRepository
{
    protected ?string $deletedAtColumn = 'deleted_at';

    public function __construct(Connection $db)
    {
        parent::__construct($db, Product::class, 'products');
    }
}
```

Solo Repository `Repository` is final. Construct it from the application's DBAL `Connection` and a `Mapping`; keep domain-specific methods in a service or a wrapper that owns that repository. Inject the same connection separately wherever the application needs SQL or transactions.

```php
use Doctrine\DBAL\Types\Types;
use Solo\Repository\Mapping;
use Solo\Repository\Repository;
use Solo\Repository\Translations;

final class Product
{
    public ?object $category = null;
    /** @var list<object> */
    public array $tags = [];

    public function __construct(
        public readonly int $id,
        public readonly string $sku,
        public readonly string $price,
        public readonly ?string $name,
        public readonly ?DateTimeImmutable $deletedAt,
    ) {
    }
}

$products = new Repository($db, new Mapping(
    table: 'products',
    columns: [
        'id' => Types::INTEGER,
        'category_id' => Types::INTEGER,
        'sku' => Types::STRING,
        'price' => Types::DECIMAL,
        'status' => Types::STRING,
        'deleted_at' => Types::DATETIME_IMMUTABLE,
    ],
    hydrate: static fn(array $row): Product => new Product(
        id: $row['id'],
        sku: $row['sku'],
        price: $row['price'],
        name: $row['name'],
        deletedAt: $row['deleted_at'],
    ),
    softDeleteColumn: 'deleted_at',
    translations: new Translations(
        table: 'product_translations',
        foreignKey: 'product_id',
        columns: ['name' => Types::STRING, 'description' => Types::TEXT],
        defaultLocale: 'en',
    ),
));
```

The application's schema must match this mapping. `Mapping` does not create tables or discover columns. `columns` is an explicit allowlist keyed by **database column names**; those names also appear in criteria, patches and projected rows. Table names are plain identifiers, without a schema prefix. The primary key defaults to `id`, must be mapped and must be a single integer, string, ASCII string or GUID key.

Before `hydrate` runs, DBAL converts every selected mapped field: `decimal` remains a string, `boolean` becomes a PHP boolean, JSON is decoded and `datetime_immutable` becomes `DateTimeImmutable`. Preserve NULL in nullable fields. Update old mappers that call `json_decode()` or construct dates from raw strings; they now receive converted values. You can reuse `Product::fromArray(...)` as the callback if it already accepts these types. The mapper need not expose database IDs or foreign keys as public model properties.

For application-assigned IDs, replace `$autoIncrement = false` with `generatedId: false` and supply the ID on every insert. Use `Types::GUID` or `Types::STRING` for UUIDs as appropriate for the schema; DB-generated UUIDs are outside the generated-ID contract. Integer IDs accept integers or canonical decimal strings, not arbitrary text, leading-zero strings or overflowing smallint/integer PHP values. String IDs such as `'01'` retain their spelling. An explicit identity ID still has to be legal in the database schema; Solo Repository does not override `GENERATED ALWAYS` or repair sequences after manual inserts.

That ID validation applies to separate ID arguments and explicit primary keys in insert data. General criteria such as `findBy(['id' => $input])` use the mapped DBAL conversion instead; validate external filter input in the application.

## 3. Move reads to an immutable ReadQuery

| V3 | Solo Repository |
|---|---|
| `$repo->find($id)` | `$repo->read()->find($id)` |
| `$repo->findOneBy($criteria)` | `$repo->read()->findOneBy($criteria)` |
| `$repo->findAll()` | `$repo->read()->findBy()` |
| `$repo->findBy($criteria, $order)` | `$repo->read()->findBy($criteria, $order)` |
| `$repo->count($criteria)` / `exists($criteria)` | `$repo->read()->count($criteria)` / `exists($criteria)` |
| `withLocale()/with()` modify the next read | Their return value is an independent, reusable `ReadQuery` |

`withLocale()` and `with()` are also entry points on `Repository`. **Capture their return value**: calling `$products->withLocale('uk');` and then starting a separate `$products->read()` does not configure the latter.

```php
$default = $products->read();
$uk = $default->withLocale('uk', fallback: 'en');
$catalog = $uk->with(['category', 'tags']); // Requires the relations below.

$first = $catalog->find(1);
$again = $catalog->find(1); // Same locale/includes; settings are not consumed.
$english = $default->find(1); // Still uses the mapping's default locale.
```

Every `with*` returns a new query. `with([...])` replaces the complete loading list; it does not append to an earlier list. `with([])` removes eager loads from that query branch.

### Convert page numbers to offsets

V3's fourth argument was a **one-based page number**. Solo Repository's fourth argument is a **zero-based row offset**. A positional call can still type-check while selecting the wrong page.

```php
// V3: page 3, 20 rows per page.
$oldProducts->findBy(['status' => 'active'], ['id' => 'ASC'], 20, 3);

// Solo Repository: the equivalent page starts after 40 rows.
$page = 3;
$perPage = 20;
$items = $products->read()->findBy(
    ['status' => 'active'],
    orderBy: ['id' => 'ASC'],
    limit: $perPage,
    offset: ($page - 1) * $perPage,
);
```

Validate page/per-page values in the application. Negative limit/offset is rejected; `limit: 0` returns an empty list. Pass `[]` rather than `null` for omitted sorting. Without `orderBy`, order is unspecified; append the unique PK to nonunique sort fields when paginating. Count totals explicitly only when the UI needs them.

### Missing records and projections

Both packages’ `find()/findOneBy()` return NULL when no visible row matches. Solo Repository has no `findOrFail()`; keep that policy in the application:

```php
$product = $products->read()->find($id)
    ?? throw new RuntimeException('Product not found.');
```

Use `rows()` for partial results, rather than hydrating an incomplete model:

```php
$rows = $products->withLocale('uk', 'en')->rows(
    ['id', 'sku', 'name'],
    ['status' => 'active'],
    ['id' => 'ASC'],
);
```

Projected values are still converted through DBAL. `rows()` rejects nonempty eager loads; use `$catalog->with([])->rows(...)`. `count()/exists()` do not load relations, even on a query with `with()`.

## 4. Rewrite criteria explicitly

The array shorthand is still an AND of fields, but operators now belong in the **key**. Old nested operator arrays and automatic IN lists are rejected.

| V3 criterion | Solo Repository criterion |
|---|---|
| `['status' => 'active']` | Unchanged |
| `['price' => ['>=' => '10.00']]` | `['price >=' => '10.00']` |
| `['status' => ['<>' => 'draft']]` | `['status !=' => 'draft']` |
| `['id' => [1, 2]]` | `['id IN' => [1, 2]]` |
| `['id' => ['NOT IN' => [1, 2]]]` | `['id NOT IN' => [1, 2]]` |
| `['name' => ['LIKE' => '%chair%']]` | `['name LIKE' => '%chair%']` |
| `['category_id' => ['!=' => null]]` | `['category_id !=' => null]` |
| `['price' => ['BETWEEN' => ['10.00', '20.00']]]` | `['price >=' => '10.00', 'price <=' => '20.00']` |
| `['OR' => [['status' => 'active'], ['sku' => 'special']]]` | `Filter::any(['status' => 'active'], ['sku' => 'special'])` |

The full operator set is `=`, `!=`, `>`, `>=`, `<`, `<=`, `IN`, `NOT IN`, `LIKE` and `NOT LIKE`. Equality may omit `=`. BETWEEN's replacement above preserves inclusive boundaries. For mapped datetime fields, supply the corresponding PHP date objects so DBAL performs conversion.

`IN/NOT IN` require an array, including for one value. NULL inside IN and nested arrays are rejected. `['id IN' => []]` is false; `['id NOT IN' => []]` is true. NULL is accepted only with `=` or `!=`, producing `IS NULL` or `IS NOT NULL`. LIKE keeps SQL wildcard behavior; parameter binding does not escape `%` or `_` into literal characters.

Use `Filter::all(...)`, `Filter::any(...)` and `Filter::not(...)` for groups. `all/any` require at least one argument; `[]` itself is true. NOT follows SQL NULL logic: `Filter::not(['price >' => '10.00'])` does not include NULL prices unless you explicitly add that alternative.

A statement has a conservative budget of 900 bound parameters, including translation and related-filter parameters. A large root IN filter is rejected rather than split into queries that could change OR, ordering or pagination semantics. Automatic chunking is limited to internal loading and bulk-writing operations.

### Replace named scopes with application functions

There is no `scopes()` hook or virtual criterion key. Translate validated user input into criteria before calling the repository:

```php
use Solo\Repository\Filter;

function catalogCriteria(?string $minimumPrice, ?string $tagSlug): array|Filter
{
    return Filter::all(
        ['status' => 'active'],
        $minimumPrice === null ? [] : ['price >=' => $minimumPrice],
        $tagSlug === null ? [] : Filter::related('tags', ['slug' => $tagSlug]),
    );
}

$items = $products->withLocale('uk', 'en')->findBy(
    catalogCriteria('10.00', 'sale'),
    ['id' => 'ASC'],
);
```

An unrecognized scope key now fails as an unknown mapped column. Validate input names and values at the application boundary instead of passing an arbitrary request payload as criteria.

### Replace relation dot notation with related()

```php
// V3
$oldProducts->findBy(['tags.slug' => 'sale', 'tags.enabled' => true]);

// Solo Repository: a single related row must satisfy both conditions.
$products->read()->findBy(Filter::related('tags', [
    'slug' => 'sale',
    'enabled' => true,
]));

// Two separate EXISTS predicates may match different related rows.
$products->read()->findBy(Filter::all(
    Filter::related('tags', ['slug' => 'sale']),
    Filter::related('tags', ['slug' => 'new']),
));

// V3 !tags.slug => 'sale' becomes NOT EXISTS.
$products->read()->findBy(Filter::not(
    Filter::related('tags', ['slug' => 'sale']),
));

// Nested filtering is explicit, if category also maps a parent relation.
$products->read()->findBy(Filter::related(
    'category',
    Filter::related('parent', ['name' => 'Furniture']),
));
```

`Filter::related('tags', Filter::any(...))` keeps OR inside one related-row predicate. Dotted fields and raw SQL are not accepted as criteria. Relation filters select owners without multiplying them; they do not restrict the collection loaded by `with()`. Their target visibility defaults to `Active` independently of root visibility.

`updateBy()/deleteBy()` accept only base-table fields and logical groups. Translated fields and `Filter::related` are read-only predicates. Move complex UPDATE/DELETE statements to DBAL with explicit scope and transaction semantics. An empty criterion, or a criterion normalized to true, is rejected for these writes, even on soft-deletable mappings. A false criterion affects zero rows. This guard is not a proof that every nonempty predicate is selective.

## 5. Adapt writes and return values

| V3 call | Solo Repository replacement and result |
|---|---|
| `create($data): object` | `insertGetId($data): int|string`; read explicitly if needed |
| `insert($data): int` | `insert($data): int`, affected rows |
| `update($id, $patch): object` | `update($id, $patch): int`, affected rows |
| `updateBy()/delete()/deleteBy()/restore()` | Affected rows; apply Solo Repository's visibility and criteria rules |
| `forceDelete($id)` | `purge($id)` |
| `forceDeleteBy($criteria)` | Explicit application SQL through DBAL; no `purgeBy()` |

`insert()` and `insertGetId()` each perform an INSERT. Choose one; do not call both for the same row. Generated IDs use the platform's supported mechanism. For MySQL generated integer keys, omit the ID instead of passing zero.

```php
$id = $products->insertGetId([
    'sku' => 'CHAIR-1',
    'price' => '29.90',
    'status' => 'active',
]);
$products->translations()->save($id, 'en', ['name' => 'Chair']);
$affected = $products->update($id, ['price' => '31.50']);
$product = $products->withLocale('uk', 'en')->find($id);
```

Wrap related writes in a transaction when they must succeed together; the example above shows individual operations only. Unlike v3 `create()/update()`, Solo Repository does not reread the model or throw a not-found exception after writing. An UPDATE result of zero can mean a missing/inactive row or an unchanged value, depending on the driver. Do not treat it as a portable not-found test.

Patches leave omitted fields unchanged; explicit NULL writes NULL. Unknown fields, translated fields, PK changes and soft-delete-column changes are rejected by `update()/updateBy()`. Use dedicated operations for translations and visibility. An empty patch is a no-op after validation.

`insertMany($rows, batchSize: 500)` requires every row to contain the same set of columns; key order may differ. V3 grouped heterogeneous rows automatically. Split them into homogeneous calls in an outer transaction if you need that workflow. Solo Repository validates the batch first, chunks by the parameter budget and rolls back all its chunks on failure; it returns affected rows, not IDs or models.

## 6. Migrate translations and stored data

Replace `$translationConfig['fields']` with typed `Translations::columns` and specify a mandatory `defaultLocale`. The default read now resolves that locale without fallback. A former `$repo->find($id)` without locale no longer means “no translation JOIN” when translations are configured.

`withLocale('uk', fallback: 'en')` overrides the default. The named parameter is now `fallback`, not v3's `fallbackLocale`. Passing no fallback does not implicitly reuse the mapping's default language.

| Primary-locale field | Fallback field | Solo Repository resolved value |
|---|---|---|
| No translation row | `'Chair'` | `'Chair'` |
| NULL | `'Chair'` | `'Chair'` |
| `''` | `'Chair'` | `''` |
| `'0'` | `'Chair'` | `'0'` |
| NULL | Missing/NULL | NULL |

**V3 treated an empty string as missing when fallback was enabled; Solo Repository preserves it.** Before switching reads, decide which historical empty strings mean “untranslated” and migrate those values to NULL. Keep intentional empty values as empty strings. Do this per field with application knowledge, not a blind conversion of every empty value.

The same resolved expression is used in SELECT, WHERE and ORDER BY, including related filters. A fallback name can therefore now satisfy a filter even when the primary translation is missing. Compare existing catalog search and sorting expectations, not just rendered text.

There is no `withoutLocale()`. Start a fresh default read to use `defaultLocale`, request only base fields with `read()->rows(['id', 'sku'])`, or use DBAL for direct physical-table access. Base-only projections/counts do not need translation JOINs unless criteria/order require translated fields.

### Audit translation constraints first

- A translation FK must reference the owner's PK, with matching ID type and comparison/collation rules.
- Require UNIQUE `(foreignKey, localeColumn)` and non-NULL owner/locale keys. It must identify exactly one translation per logical owner and locale.
- Translation columns must be nullable string/text fields and must not overlap base mapped fields. The service FK and locale column are not translated fields.
- Apart from a separate generated surrogate PK, no additional unique constraint may produce a conflict for the supplied translation data. MySQL's upsert cannot target only the owner/locale unique constraint.
- Confirm how deleting an owner should handle translations through FK actions.

For example, find duplicate pairs before creating or correcting the unique constraint:

```sql
SELECT product_id, locale, COUNT(*)
FROM product_translations
GROUP BY product_id, locale
HAVING COUNT(*) > 1;
```

Also audit string-key collation: a binary translation FK could allow `Owner` and `owner` as separate unique pairs while a case-insensitive owner PK treats them as one owner. The repository does not introspect or repair these schema invariants.

### Use TranslationStore for explicit patches

```php
$translations = $products->translations();
$translations->save($id, 'uk', ['name' => 'Стілець']);
$translations->save($id, 'uk', ['description' => null]);
$ownTranslation = $translations->get($id, 'uk'); // All translated fields, no fallback.
$deletedRows = $translations->delete($id, 'uk'); // Only this owner/locale.
```

`save()` is one atomic native upsert and returns void. Existing omitted fields remain unchanged. On a new row, omitted translated fields are explicitly NULL, even if the database column has a DEFAULT. Values must be string or NULL; unknown/protected fields are rejected rather than silently ignored. An empty patch validates ID/locale and performs no SQL. `get()` returns NULL only when the row is missing; an existing all-NULL translation returns an array.

There is no `seedTranslations()` equivalent with “insert if missing” semantics. For a **newly created owner**, save each desired locale inside the creation transaction. Do not replace repeatable v3 seeding with a blind loop of `save()`: it would overwrite existing translations. A repeatable preserve-existing seed operation belongs in application SQL with a targeted conflict policy; a get-then-save loop alone does not make that policy safe under concurrent writers.

## 7. Replace relation definitions and setters

V3 relation definitions use `Solo\BaseRepository\Relation`, repository property names and setter names. Solo Repository definitions are in **`Solo\Repository\Relations`**, plural, and use callbacks:

```php
use Solo\Repository\Relations\BelongsTo;
use Solo\Repository\Relations\BelongsToMany;

// Add these entries to the relations argument of the products Mapping.
$relations = [
    'category' => new BelongsTo(
        repository: static fn(): Repository => $categories,
        foreignKey: 'category_id',
        assign: static function (Product $product, ?object $category): Product {
            $product->category = $category;
            return $product;
        },
    ),
    'tags' => new BelongsToMany(
        repository: static fn(): Repository => $tags,
        pivotTable: 'product_tags', // V3 called this constructor argument "pivot".
        foreignPivotKey: 'product_id',
        relatedPivotKey: 'tag_id',
        assign: static function (Product $product, array $tags): Product {
            $product->tags = $tags;
            return $product;
        },
    ),
];
```

Construct `$categories` and `$tags` as ordinary mapped repositories. Pass `relations: $relations` when constructing the products mapping. Keep the same `Connection` instance for every repository in the graph; a separately constructed connection with the same DSN is not sufficient. Lazy repository callbacks support cyclic configuration and are checked when the relation is resolved.

`assign` must return the owner object, either the mutated object or a new immutable copy. A legacy void setter needs a wrapper that calls the setter **and returns the owner**. To-one callbacks receive an object or NULL; to-many callbacks receive a list, including an empty list. Every relation definition requires a callback, even if the current caller uses it only for filtering.

`BelongsTo` uses an FK on the owner. `HasOne/HasMany` use an FK on the target; `HasOne` requires that FK to be unique. `BelongsToMany` requires UNIQUE on its pivot pair and both foreign keys. Audit compatible types and matching PK/FK collations, including translations. Alternative-key/composite-key relations, cross-connection graphs and pivot attributes are outside 1.0; model a pivot with business fields as a separate repository.

Move relation-level sorting/constraints to `Load`:

```php
use Solo\Repository\Load;

$items = $products->withLocale('uk', 'en')->with([
    'category',
    new Load('tags', criteria: ['enabled' => true], orderBy: ['name' => 'ASC']),
])->findBy(Filter::related('tags', ['slug' => 'sale']));
```

The related filter selects owners that have a visible sale tag. The Load returns all visible enabled tags for those owners; it does not implicitly repeat the sale predicate. Nested eager loading still uses finite paths such as `with(['category.parent'])`. Dots are supported in loading paths, not in filter field names. Per-parent limits and per-relation locale overrides are not provided.

The effective locale/fallback pair flows to loaded relations and related filters. With no explicit pair, the root mapping's default is inherited. If the root has no translations, the first translated mapping on each branch supplies its default; explicitly set the root locale when the whole graph must use one language.

Loading is batched by owner keys; it does not access relations lazily from models. Many-to-many loading uses pivot and target queries per owner batch. The same database row is not guaranteed to have a single shared PHP object across queries.

## 8. Make soft-delete visibility explicit

Replace v3's `['deleted_at' => '*']` sentinel and visibility-by-criterion conventions:

```php
use Solo\Repository\Visibility;

$all = $products->read()->withVisibility(Visibility::All)->findBy();
$deleted = $products->read()->withVisibility(Visibility::Deleted)->findBy();

$withDeletedTags = $products->with([
    new Load('tags', visibility: Visibility::All),
])->find($id);

$hasDeletedTags = $products->read()->findBy(Filter::related(
    'tags',
    visibility: Visibility::Deleted,
));
```

`Active` is the default. Root `withVisibility()` does not change relation visibility. Visibility is applied outside user OR groups; mentioning `deleted_at` in criteria does not disable it. Without a soft-delete mapping, `Active/All` are equivalent and `Deleted` is rejected.

`update()/updateBy()` only modify active rows. Soft `delete()/deleteBy()` set a UTC timestamp on active rows; repeating a delete returns zero without replacing the timestamp. `restore($id)` only affects deleted rows and requires soft delete configuration. `purge($id)` deletes physically regardless of visibility. Without soft delete, `delete` is already physical. Configure FK actions or explicit application cleanup for dependent translations and pivots.

## 9. Move pivot writes and transactions to explicit services

| V3 | Solo Repository |
|---|---|
| `attach('tags', $id, $ids)` | `links('tags')->attach($id, $ids)` |
| `detach('tags', $id, $ids)` | `links('tags')->detach($id, $ids)` |
| `detach('tags', $id)` or `detach('tags', $id, [])` | `links('tags')->sync($id, [])` |
| `sync('tags', $id, $ids)` | `links('tags')->sync($id, $ids)` |

**An empty detach list is a no-op**, as is an empty attach list. Only `sync($id, [])` means “remove all links”. IDs are converted by the target PK type and deduplicated; their input order is not persisted.

LinkSet membership is physical: hidden/deleted targets still count, and hidden owners can be edited. A filtered UI collection is not automatically the complete physical set. Calling sync with only that collection's visible IDs would remove omitted hidden memberships; choose that policy deliberately.

Use the injected connection to combine base patches, translation patches and pivot replacement:

```php
$db->transactional(function () use ($products, $id): void {
    $products->update($id, ['sku' => 'CHAIR-UPDATED']);
    $products->translations()->save($id, 'uk', ['name' => 'Оновлений стілець']);
    $products->links('tags')->sync($id, [2, 3]);
});
```

All repositories and write services must share that connection. CRUD uses the current transaction without adding one. `insertMany` and nonempty LinkSet operations use DBAL transactions/nested savepoints; translation save is a single native upsert that participates in the caller's transaction. Database errors, including FK and unique violations, propagate. Catch exceptions outside the transaction boundary when the entire workflow must roll back.

Atomic rollback does not serialize concurrent sync/attach calls or provide automatic retries. If concurrent writers must agree on a final tag set, apply the application's locking/versioning policy in the transaction. Physical string-key operations may read canonical IDs to respect database collation; do not assume that LinkSet is a write-only operation. Missing/orphan targets are not an orphan-cleanup feature; enforce the required FKs.

## 10. Replace repository wrappers with application code and DBAL

The following v3 extension points have no equivalent in Solo Repository:

| V3 feature | Migration |
|---|---|
| `RepositoryInterface` / subclassing `BaseRepository` | Own an instance of final `Repository`; define an application interface if needed |
| `getConnection()` and protected `queryBuilder()/table()` | Inject `Connection` into the application component |
| `beginTransaction()/commit()/rollBack()/withTransaction()/inTransaction()` | Use DBAL `Connection` transaction methods |
| `lockForUpdate()/withLock()` | Application-owned, platform-specific locking on the same connection |
| `sum()/avg()/min()/max()/aggregate()` | Explicit DBAL aggregate SQL |
| `afterWrite()` | Explicit application invalidation/events at the intended transaction boundary |
| `scopes()` | Application functions returning criteria |
| Reflection/static mapper convention | Explicit `hydrate` callback in `Mapping` |

For example, an application can keep a typed aggregate method backed by DBAL:

```php
$total = $db->fetchOne(
    'SELECT SUM(price) FROM products WHERE status = ? AND deleted_at IS NULL',
    ['active'],
    [Types::STRING],
);
```

This SQL owns its soft-delete and locale semantics; repository policies are not applied to arbitrary DBAL statements. Bind values and validate/quote dynamic identifiers. Raw DBAL results do not automatically pass through the repository's mapping.

For cache invalidation or outgoing events, decide whether they belong after a successful statement or after the encompassing transaction commits. V3's `afterWrite()` ran before commit and only observed that repository's base-table writes; blindly moving its body after every new call would still miss translation/pivot changes and rollback behavior. Keep that policy in the service orchestrating the workflow; no event bus or unit of work is added.

For exports, use `iterateById($criteria, batchSize: 500)` with an integer PK instead of paging a growing OFFSET. It loads ascending IDs in bounded batches and releases each consumed page. It is not a snapshot, does not support another ordering, and cannot bound memory if the consumer retains every model or a single owner has an enormous relation collection. Use database-specific export SQL for other requirements.

## 11. Verify a migrated consumer

Run the `examples/migration.php` smoke example, then test the consumer's actual behavior:

1. Compare stable paginated IDs and NULL/missing-record handling, including callers that used the old positional page argument.
2. Check all mapped types and old hydration conversions; verify patches preserve omitted fields.
3. Compare localized rendering, filtering and sorting for missing, NULL and empty translations.
4. Audit FK/unique/collation invariants before enabling nested eager loads and relation predicates.
5. Verify owner selection separately from collection loading, root/target visibility and physical sync semantics.
6. Cause a late FK/unique error after earlier writes; verify the entire intended transaction rolls back.
7. Check query counts on a realistic catalog and memory behavior on a realistic export.
8. Replace application locks, retry policies, aggregates and invalidation hooks explicitly before switching traffic.

Use the project's [SQLite and live database suites](details.md#проверка-на-mysql-и-postgresql) for library checks. They complement consumer tests; they do not infer your domain's visibility, transaction or concurrency policies.
