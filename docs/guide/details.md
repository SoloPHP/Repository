# Behavior in detail

Solo Repository provides typed reads, explicit writes, translations with one fallback rule and batch-loaded relations. [Getting started](index.md) shows the setup, and the [public API](api.md) describes the contracts. This page explains how the pieces behave and how the package is tested.

## What is included

`Mapping`, `Repository`, `ReadQuery`, `Filter::all/any/not/related`, `Like`, `Visibility`, `Translations`, `TranslationStore`, four relation types, `Load` and `LinkSet`. Reads of models and projections, ordering by fields of `BelongsTo` targets, LIKE patterns that escape the same way on every database, locking reads, count/exists, CRUD, atomic `insertMany`, native upserts by a unique key, soft delete, batched iteration by ID, translations with one fallback rule and batch loading of nested relations. The runnable examples `examples/crud.php`, `examples/translations.php` and `examples/relations.php` use in-memory SQLite:

```sh
composer install
php examples/crud.php
php examples/translations.php
php examples/relations.php
vendor/bin/phpunit --testsuite Core
composer test
```

`Mapping` takes `translations` and `relations`. `withLocale()`, `with()` and `withVisibility()` return independent read settings. `translations()` and `links()` give a separate write API. Catalog and export scenarios are part of the integration tests.

The bound-parameter budget of a statement includes the locale parameters of its JOINs; keyset iteration keeps one of them for the continuation. By default it is the platform limit: 65,535 for PostgreSQL and MySQL/MariaDB, 32,766 for SQLite 3.32+, 900 for older SQLite and other platforms. The `parameterLimit` argument of the `Repository` constructor sets another positive budget, for example to reject large root IN filters earlier. The value must not exceed the server's limit; raise it only on a platform whose real limit is above the default. Internal key lists (relation loads, `lockForUpdate()`, deletes in `LinkSet`) are additionally capped at 5,000 keys per statement: a longer IN list can exceed the memory of MySQL's range optimizer and turn an index lookup into a full scan. Creating repositories and queries does not open a connection: the platform is detected when the first statement is built. A table name is a plain name, without a schema prefix.

An ID of an integer primary key is accepted as an int or a canonical decimal string: non-numeric values and PHP integer overflow for integer/smallint are rejected, so a wrong ID cannot turn into someone else's numeric key. Criteria values are checked against the field type the same way: an integer field takes an int or a canonical decimal string; decimal a finite number or a decimal string without an exponent; float a finite number or a numeric string with a finite value; boolean `bool`, `0` or `1` (also as a string); string fields a string, an integer or a `Stringable`; enum a string or a `Stringable` (MySQL reads an integer as the element number); `simple_array` an array. Otherwise the driver would silently turn `'12x'` into 12, and MySQL `'false'` into TRUE. Dates, intervals and bcmath numbers are checked by DBAL itself; JSON, binary and custom types get the value as is. The form of a value is checked, not the range, precision or scale of the column: that stays a database constraint. SQLite, for example, stores decimal as REAL, and a value that is too large becomes infinity there.

The main test suite uses SQLite. A separate `Database` suite runs on real MySQL 8.4, MariaDB 12.3 and PostgreSQL 17 with native PDO prepares. It runs apart from `composer test`: a missing URL, an unreachable database and skipped tests count as failures.

Solo Repository turns database rows into ordinary PHP objects, resolves translations with fallback and loads relations in batches. Doctrine DBAL stays the SQL API. The package does not track changes of objects and does not save object graphs automatically.

## Testing on MySQL, MariaDB and PostgreSQL

The shared suite checks generated IDs and CRUD, decimal/bool/date/JSON conversion, LIKE escaping (on MySQL and MariaDB also under `NO_BACKSLASH_ESCAPES`), soft delete and keyset iteration, native row upserts and PATCH upserts of translations, the single fallback rule, four relation types, nested EXISTS and ordering by `BelongsTo` paths. Locking reads are checked with a second session: which rows the lock holds and how such a read differs from the snapshot of a REPEATABLE READ transaction on each database. A second session also stores a pair between the read and the insert of `attach()`: at the server's isolation level and at REPEATABLE READ the suite checks where the conflict is skipped and where the transaction is refused. Separate scenarios check batch boundaries, a statement with the server's maximum number of parameters (65,535), ordering of a 905-item collection, release of the previous page, string keys and native PostgreSQL UUIDs, rollback of a late batch and continuation of an outer transaction after a savepoint. The schemas have real FK and UNIQUE constraints; the tests run SQL on the servers without platform mocks.

`.github/workflows/tests.yml` keeps three jobs of the main SQLite suite and runs `Database` on each of the three databases for the same combinations:

| PHP | DBAL constraint at install |
|---|---|
| 8.3 | `4.3.*`, the minimum supported branch |
| 8.3 | `^4.3`, the latest compatible release |
| 8.5 | `^4.3`, the latest compatible release |

Your own test database needs `REPOSITORY_DATABASE_URL` and the matching `pdo_mysql` or `pdo_pgsql` extension. `mysql://` (MySQL and MariaDB) and `postgresql://` URLs are supported; the database name must end in `_test`. The suite creates and drops its own fixture tables with the `repo_` prefix, so it needs a dedicated test database:

```sh
REPOSITORY_DATABASE_URL='mysql://repository:repository@127.0.0.1:3306/repository_test' composer test:database
REPOSITORY_DATABASE_URL='postgresql://repository:repository@127.0.0.1:5432/repository_test' composer test:database
```

Locally, Docker with Compose v2 is enough. `compose.database.yml` creates the `solo-repository-database` project with MySQL 8.4, MariaDB 12.3, PostgreSQL 17 and a PHP runner. No ports are published to the host, and the data of all databases lives in tmpfs. The runner mounts the repository read-only; dependencies are installed by a separate command before the tests run:

```sh
docker compose -f compose.database.yml build tests
docker run --rm -v "$PWD":/workspace -w /workspace \
  solo-repository-database-tests:local composer install --no-interaction --prefer-dist

# MySQL: the default URL.
docker compose -f compose.database.yml run --rm tests

# MariaDB and PostgreSQL: the same suite, another URL.
REPOSITORY_DATABASE_URL='mysql://repository:repository@mariadb:3306/repository_test' \
  docker compose -f compose.database.yml run --rm tests
REPOSITORY_DATABASE_URL='postgresql://repository:repository@postgres:5432/repository_test' \
  docker compose -f compose.database.yml run --rm tests

# Remove the containers, the network and the temporary data.
docker compose -f compose.database.yml down --volumes --remove-orphans
```

Compose waits for the health checks of all three databases. The runner's command is `php vendor/bin/phpunit -c phpunit.database.xml --do-not-cache-result`; connection errors are not turned into skips. Pass `PHP_VERSION=8.5` at build time to pick the PHP version. To check the minimum DBAL branch locally, run `composer update --with='doctrine/dbal:4.3.*'` in the same runner instead of `composer install`; this changes the local `composer.lock` and `vendor`, so restore the dependencies you need afterwards.

MySQL 8.4, MariaDB 12.3 and PostgreSQL 17 are the verified server platforms. MariaDB uses the same code paths as MySQL (generated IDs, upserts of rows and translations); its locking reads have no `FOR UPDATE OF`. Other platforms are not supported.

## Design decisions

| Area | Decision |
|---|---|
| Foundation | PHP ≥ 8.3, Doctrine DBAL 4.x with 4.3 as the minimum. No other runtime dependencies |
| Model | Any object, built by an explicit `hydrate(array): object` function. No base `Entity`, no reflection |
| Configuration | One immutable `Mapping`: table, column types, key, hydrator and optional features |
| Reads | `Repository` creates an independent `ReadQuery`; `withLocale()` and `with()` settings are kept |
| Writes | Explicit operations on data; the result is affected rows or an explicitly requested ID. No automatic re-read of the model |
| Translations | One resolver for the value in SELECT, filters and sorting; fallback per field |
| Relations | Explicit definitions and assign functions; batch loading instead of queries from objects |
| Complex SQL | A real DBAL `QueryBuilder`: `ReadQuery::queryBuilder()` with the mapping's criteria, or the application's own `Connection` |

Not part of 1.2: unit of work, identity map, lazy proxies, dirty tracking, cascading graph saves, schema generation, query cache, event bus, advisory locks, a container of its own and separate Composer packages. The application defines business operations, transactions across several repositories and retry rules.

## Structure

```text
Repository<T> ── Mapping<T> + Connection
   │
   ├── read() / withLocale() / with() → ReadQuery<T>
   │      └── terminal → DBAL QueryBuilder → typed rows → hydrate
   │                                              └── batch relations → assign
   │
   ├── insert / update / delete → DBAL
   ├── translations() → TranslationStore
   └── links(name) → LinkSet
```

Public types: `Repository<T>`, `Mapping<T>`, `ReadQuery<T>`, `Filter`, `Like`, `Visibility`, `Load`, `Translations`, four relation definitions, `TranslationStore` and `LinkSet`. The type `T` is given by PHPDoc generics for static analysis and is covariant; relation definitions are generic over owner and target, so the analyser checks `assign` against the target's mapping. At runtime the package checks that the hydrator and `assign` return an object. The `Internal` namespace and members marked `@internal` are not a supported application API: the constructors of `ReadQuery`, `LinkSet` and `TranslationStore` (they come from `Repository`), the properties of `Filter` and `Relation::resolve()`.

Inside, a few small components each own one responsibility:

- `Database`: the connection plus platform facts (dialect, parameter limit, quoting, LIKE escape), resolved lazily.
- `ReadExecutor`: creates a new DBAL builder for every execution.
- `PredicateCompiler`: validates filters as a whole, folds constants into a typed tree of conditions and renders it into DBAL expressions and parameters.
- `FieldResolver`: checks a column and returns a plain or a localized SQL expression.
- `RowMapper`: applies DBAL types and calls the hydrator.
- `RelationLoader`: groups rows, loads relations in batches and calls `assign`.
- `Repository`, `TranslationStore` and `LinkSet`: explicit write operations, including the narrow platform variants of upserts and generated IDs.

The package has no SQL AST of its own, no dialect engine and no public `select/from/join/groupBy/having` methods. For such queries the application uses DBAL directly. To keep the mapping's criteria in such SQL, `ReadQuery::queryBuilder($alias, $criteria)` returns a DBAL `QueryBuilder` with the table under the alias in FROM, the criteria and visibility in WHERE, and the translation JOINs the criteria need in the query's locale. It has no SELECT: the application adds it, along with joins, grouping and sorting. Add your own conditions with `andWhere()`, not `where()`: `where()` in DBAL replaces the whole WHERE clause, and the criteria and soft delete would silently disappear. The alias is a lowercase SQL identifier, so that unquoted references such as `s.product_id` match it on every platform; translation JOINs are named `<alias>_translation` and `<alias>_fallback`. Criteria values are bound as named parameters, so bind your own values with `createNamedParameter()` too. An `IN` list is one `ArrayParameterType` parameter that DBAL expands at execution, so run the builder's SQL through the builder itself or together with `getParameterTypes()`. Everything the application adds is plain DBAL: joined tables get no visibility or locale, and result rows are not converted by the mapping's types. A non-empty `with()` is an error.

## Mapping and models

`Mapping` holds `table`, `columns`, `hydrate`, `primaryKey = 'id'` (NULL for a table without a single-column key), `generatedId = true`, and the optional `translations`, `relations` and `softDeleteColumn`.

`hydrate` may be NULL for a table that is read only as rows, such as a link table without a model of its own. `rows()`, aggregates, `queryBuilder()`, related filters and writes work for it, while `find()`, `findOneBy()`, `findBy()`, `iterateById()` and loading it as a relation target throw `LogicException` before SQL.

`columns` is an associative array of `column_name => DBAL type name`. These names are used in filters, sorting, writes and row results. It is also an allowlist: an unknown field is an error before SQL. camelCase, private properties and constructors stay the hydrator's job. SQL identifiers are validated and quoted by DBAL; values are always bound as parameters.

One `int|string` primary key is supported, or none. `primaryKey: null` describes a table without a single-column key, for example with a composite key: its rows are read, inserted and changed by criteria (`findBy`, `rows`, `count`, aggregates, `queryBuilder`, `insert`, `insertMany`, `updateBy`, `deleteBy`, `purgeBy`). Operations by ID (`find`, `insertGetId`, `update`, `delete`, `restore`, `purge`, `lockForUpdate`) throw `LogicException`, and `iterateById` throws `InvalidArgumentException`, all before SQL. Such a table has no generated ID, and an inserted row has at least one column.

Without a primary key there are no translations, and the only possible relation is `BelongsTo`: `HasOne`, `HasMany` and `BelongsToMany` find their owner by its key. `HasOne` and `HasMany` can load such a table, but `BelongsTo` and `BelongsToMany` cannot, since they reference the target's key. `Filter::related` through its `BelongsTo` works with any key type, while loading needs an integer target key: string keys are compared in SQL by the database collation, through the owner's primary key. Addressing a row by a composite key and relations over alternative unique keys are outside 1.2. The hydrator gets every declared base and translated column, not `SELECT *`.

A given primary key must be part of `columns`; a relation's foreign key is part of the `columns` of its side. `generatedId: true` is allowed only for a compatible integer primary key. A mapping is validated at once; the requirements on a lazy target side are checked when the relation is first resolved.

After a read, values are converted by DBAL before `hydrate` is called. `decimal`, for example, stays a string, and `datetime_immutable` becomes a `DateTimeImmutable`. For writes and comparisons, DBAL gets the type name and converts the value once. An `IN` list, after each element is checked, is converted by the field type into database values and bound as one DBAL list parameter, which DBAL expands at execution; a type without a list binding, such as boolean, binds each element separately. Every value of a list counts toward the parameter budget. Custom types are registered in DBAL; there is no second registry of codecs. Types that need special SQL wrappers are not part of the first contract.

For relation loads, keys are taken from the **converted rows**, before `hydrate`. The loader does not read `$model->id` or `$model->category_id`. Related key types must be compatible. For string keys, the foreign key and its primary key must use the same comparison rules (collation). The `target` factory returns the target's mapping and is called lazily, which allows cyclic configuration without a separate registry. Related rows are read through the owner repository's connection.

## Reads

`Repository<T>` has three entry points for reads:

```php
read(): ReadQuery;                         // default settings
withLocale(string $locale, ?string $fallback = null): ReadQuery;
with(array $relations): ReadQuery;         // list<string|Load>
```

The last two are short forms of `read()->withLocale(...)` and `read()->with(...)`. A plain read looks like `$products->read()->find($id)`. The application's business methods live in a service that owns the repository, or in a typed subclass of `Repository`: a `readonly` class fixes the mapping in its constructor, a container injects it by type, and static analysis takes the model from `@extends Repository<Model>`. The public methods of `Repository` are final: a subclass adds methods of its own but does not change the inherited reads and writes.

The `ReadQuery<T>` contract:

```php
withLocale(string $locale, ?string $fallback = null): self;
with(array $relations): self;              // list<string|Load>
withVisibility(Visibility $visibility): self;
forUpdate(): self;                         // locking read

find(int|string $id): ?object;             // T|null
findOneBy(array|Filter $criteria, array $orderBy = []): ?object;
findBy(
    array|Filter $criteria = [],
    array $orderBy = [],
    ?int $limit = null,
    int $offset = 0,
): array;                                 // list<T>
exists(array|Filter $criteria = []): bool;
count(array|Filter $criteria = []): int;
sum(string $field, array|Filter $criteria = []): mixed;   // zero of its type when no rows match
min(string $field, array|Filter $criteria = []): mixed;   // NULL when no rows match
max(string $field, array|Filter $criteria = []): mixed;
avg(string $field, array|Filter $criteria = []): ?float;
rows(
    array $columns,
    array|Filter $criteria = [],
    array $orderBy = [],
    ?int $limit = null,
    int $offset = 0,
): array;                                 // list<array<string, mixed>>
iterateById(array|Filter $criteria = [], int $batchSize = 500): iterable;
queryBuilder(string $alias, array|Filter $criteria = []): QueryBuilder;
```

Every `with*` returns a new object. Terminal methods do not reset the settings: a query can be repeated and branched independently. `with()` replaces the whole list of loads, and `with([])` clears it. A DBAL builder is never kept as shared mutable state.

`find` and `findOneBy` limit the SQL to one row. `exists` selects a constant with LIMIT 1. `count` counts root rows; `with()` loads do not affect these two operations. None of these methods runs an extra COUNT for pagination.

`sum`, `min`, `max` and `avg` aggregate one readable field (a translated one too, in the query's locale) over the same rows `count` counts: the same criteria, relation predicates and visibility. The total of a filtered list matches its filter. `sum`, `min` and `max` convert the result by the field's DBAL type: the sum of a decimal is an exact string, `max('created_at')` is a `DateTimeImmutable`. Without matching rows `sum` returns the zero of the field's type and the others return NULL. `sum` and `avg` take only smallint, integer, bigint, decimal, float and smallfloat fields; other fields are rejected before SQL. Aggregates over several tables are built with DBAL.

`rows` returns only the requested columns, converted by their DBAL types. It does not create partially filled models. A non-empty `with()` with `rows` is an error rather than being silently ignored; use a separate branch of the query with `with([])`.

Without `orderBy` the order is undefined. For a stable page, the application ends the ordering with a unique key. Negative limits and offsets and unknown sort directions are rejected; `limit: 0` returns an empty list. `findBy()` collections are materialized in full within the given limit.

An `orderBy` key of `findBy`, `findOneBy` and `rows` can be a path through `BelongsTo` relations to a field of the target: `['category.name' => 'ASC']`, `['category.parent.name' => 'DESC']`. Each target of a path is LEFT JOINed by its primary key once per statement, over the rows a load of the same relation in this read sees: the active ones, unless the read loads the relation with another visibility. A list that shows soft-deleted categories through `with([new Load('category', visibility: Visibility::All)])` is therefore also sorted by their names; only the load's visibility is taken, not its criteria. A row with an empty, dangling or invisible reference sorts as a row with NULL in that field, and the database decides where NULL goes (first in ascending order on MySQL, MariaDB and SQLite, last on PostgreSQL). A translated target field is read in the query's locale and fallback, which the target inherits as a relation load does. Paths through `HasOne`, `HasMany` and `BelongsToMany` could yield several rows and are rejected before SQL. The default order of a to-many relation and the order of a `Load` take the same paths, from the target's mapping.

`forUpdate()` makes the same read a locking one: `find`, `findOneBy`, `findBy`, `rows`, `exists` and each batch of `iterateById` run `SELECT ... FOR UPDATE` and hold the rows they read until the transaction ends; outside a transaction they throw `LogicException`. A plain read in a REPEATABLE READ transaction answers from its snapshot, while a locking read never returns an older version of a row: MySQL reads the latest committed one, and PostgreSQL and MariaDB (with `innodb_snapshot_isolation`, the default since 11.6) refuse a row changed after the snapshot with a serialization failure, and the transaction has to start over. The rows of the read's own table are locked: PostgreSQL and MySQL 8.0.1 or later (told by the server version) get `FOR UPDATE OF`, while MariaDB and MySQL 5.7, which lack it, also lock the joined rows of translations and sorting. Relation loads read without locks. Aggregates and `queryBuilder()` of a locking read throw `LogicException`. SQLite has no row locks and runs a plain SELECT. Rows are locked in the order the database reads them; transactions that lock overlapping sets of rows read them in key order or take `lockForUpdate()` first. MySQL and MariaDB lock every row they read to answer, so with an ordering no index serves (by a relation path, for example) a read with a limit locks all matching rows, not only the page.

## Filters without a second QueryBuilder

A plain array is an AND over fields:

```php
['status' => 'active', 'price >=' => '50.00', 'id IN' => [1, 2, 3]]
```

The operators are `=`, `!=`, `>`, `>=`, `<`, `<=`, `IN`, `NOT IN`, `LIKE` and `NOT LIKE`; no suffix means `=`. `= null` and `!= null` become IS NULL and IS NOT NULL. NULL with other operators, NULL inside IN and an array without an explicit IN are rejected. `IN []` is false and `NOT IN []` is true. LIKE takes the SQL wildcards `%` and `_` and escapes with `\` on every database; case, collation and NULL order depend on the database.

The escape character is the one part of LIKE that the package makes the same everywhere. PostgreSQL always escapes with `\`. SQLite has no default escape character, and under `NO_BACKSLASH_ESCAPES` neither has MySQL, nor MariaDB for single-byte character sets such as latin1; in that mode both also read the string `'\\'` as two characters. So the compiler ends every LIKE with `ESCAPE '\'` on SQLite and `ESCAPE X'5C'` on MySQL and MariaDB, where the hex literal is one backslash in every SQL mode. The character is a literal, not a bound parameter: with a bound one, MySQL and SQLite no longer plan a pattern with a fixed prefix as an index range. PostgreSQL gets no clause, which also keeps LIKE working on `bytea` fields, where an ESCAPE literal would compare bytea with text. A pattern that ends in an unpaired `\` is rejected before SQL, because the databases disagree on it. `Like::escape()`, `contains()`, `startsWith()` and `endsWith()` build patterns that match text literally.

The key carries both the field and the operator, so criteria keys are built only from fixed strings in code, never from request parameters or other input. Whoever controls a key chooses the operator: `filter[price >=]=0` would turn an equality into a comparison. When users choose the fields to filter by, map each allowed name to a fixed key.

The four `Filter` constructors group conditions and express relations:

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

`all` and `any` need at least one argument. An empty array means true. Logical groups are kept as written; simple constants are normalized. NOT follows SQL three-valued logic: `NOT(price > 10)`, for example, does not include NULL prices automatically.

`related('tags', ['color' => 'red', 'size' => 'large'])` means **one** related row with both properties and compiles to EXISTS. Two separate `related('tags', ...)` inside `all` allow two different rows. `not(related(...))` is NOT EXISTS. OR inside related stays OR. Root rows are not multiplied by matching relations.

Names such as `tags.slug`, SQL fragments, hidden scope keys and unknown fields are not accepted. Nested relations are expressed by nested `related`, not by ambiguous dot parsing. Each EXISTS has its own alias and parameters. Ordering by a collection is not supported: its aggregate and JOIN are written with DBAL.

An arbitrary root filter with a large IN is not split into several queries: that would change the semantics of OR, ordering and limits. Exceeding the parameter budget is an explicit error; another way to pass a large set is written with DBAL. Automatic splitting applies only to the loader's internal batches and to bulk inserts.

## Translations

`Translations` sets `table`, `foreignKey`, `columns`, an optional `defaultLocale` and `localeColumn = 'locale'`. Translated fields are nullable string/text, and their names do not overlap with the base columns. A UNIQUE `(foreignKey, localeColumn)` and a foreign key to the base table are required. For a portable PATCH upsert, this pair must be the only possible unique conflict when translated data is inserted; additional UNIQUE constraints on translated fields need a separate operation of the application. This restriction follows the behavior of [MySQL ON DUPLICATE KEY](https://dev.mysql.com/doc/refman/8.4/en/insert-on-duplicate.html).

The UNIQUE constraint must allow one row per owner and locale with the same ID comparison rules as the owner's primary key. For a string primary key with `COLLATE NOCASE` in SQLite, for example, the translation foreign key also needs `COLLATE NOCASE`: a binary UNIQUE would allow two translations for `Owner` and `owner`, although they are one owner. Matching types, collations and constraints are set by the application's migrations, without automatic schema introspection in the repository.

The technical `foreignKey` and `localeColumn` are not part of the translated `columns` and are not available in a patch.

A plain read uses `defaultLocale` without fallback. Without `defaultLocale`, a read that names no locale makes no translation JOIN: translated fields come back NULL, and conditions, sorting and aggregates on them throw `LogicException` until the read calls `withLocale()`. `withLocale('uk', fallback: 'en')` chooses the pair explicitly. There is no implicit global language, no fallback chain and no fallback to a base-table column. An empty locale is rejected; equal primary and fallback locales collapse into one language.

| Value for `uk` | Value for `en` | Result with fallback `en` |
|---|---|---|
| No row | `"Chair"` | `"Chair"` |
| NULL | `"Chair"` | `"Chair"` |
| `""` | `"Chair"` | `""` |
| `"0"` | `"Chair"` | `"0"` |
| `"Стілець"` | `"Chair"` | `"Стілець"` |
| NULL | No row | NULL |

Fallback is thus `COALESCE(primary.field, fallback.field)`, chosen independently for each field. Without fallback a missing row or NULL gives NULL. The hydrator must accept that value or apply a rule the application chose explicitly.

One `FieldResolver` serves the projection, WHERE, ORDER BY and conditions inside relations. A filter on `name` checks exactly the value with fallback that will be shown.

The effective language pair of the root read is inherited by relations and relation predicates. When the root has neither `withLocale()` nor `defaultLocale`, in each branch the first translated entity with a `defaultLocale` applies it, and it is inherited from there; translated entities above it read NULL in their translated fields. For one language across the whole graph, set `withLocale()` on the root, even if the root itself is not translated. A per-load language override is not part of 1.2.

When a model is read from a translated repository with `defaultLocale`, that language means a JOIN by default. `rows`, `count` and `exists` add a translation JOIN only when their fields or filters need it. Unlocalized physical rows can be read directly through DBAL; there is no separate `withoutLocale()`.

`TranslationStore` separates editing from display:

```php
get(int|string $id, string $locale): ?array;           // own fields, without fallback
all(int|string $id): array;                            // every locale of the owner, ascending
save(int|string $id, string $locale, array $patch): void;
seed(int|string $id, array $locales, array $values): int;   // rows inserted
delete(int|string $id, string $locale): int;
```

`save` is an atomic upsert of **only the given fields**. Patch values are strings or NULL. An omitted field is not changed, NULL enables fallback on read, and `""` stores an empty translation. When the row is created, translated fields that were not sent get the column default; for a column without a DEFAULT that is NULL, and fallback applies on read. An empty patch is a no-op after the ID and the locale are checked. Translated fields in a plain `insert/update` and in write criteria are rejected. Store operations use the physical ID, including a soft-deleted owner; the rules of admin editing are the application's.

Saving runs one native upsert: `ON CONFLICT` on SQLite/PostgreSQL or `ON DUPLICATE KEY UPDATE` on MySQL/MariaDB. It takes part in the transaction of the given DBAL connection; FK and constraint errors are not suppressed. On other platforms a non-empty `save` fails before SQL. Without `Mapping.translations`, `Repository::translations()` is an explicit error too.

`seed` creates a translation for each locale that does not have one yet, for example for all active languages of a new entity, and returns the number of inserted rows. Existing rows are not changed, so repeating a seed is safe. Values follow the rules of `save`, and fields not sent get the column default in a new row. Only a conflict on the `(foreignKey, localeColumn)` pair is skipped: `ON CONFLICT ... DO NOTHING` on PostgreSQL/SQLite and a no-op `ON DUPLICATE KEY UPDATE` on MySQL/MariaDB, without `INSERT IGNORE`. FK and other constraint errors still propagate.

`get` returns every declared translated field, converted by DBAL. NULL instead of an array means there is no row; an existing row with all NULLs returns an array. `all` returns the same fields for each stored locale of the owner, keyed by locale in ascending order; without translations it is an empty array.

## Relations

The `BelongsTo`, `HasOne`, `HasMany` and `BelongsToMany` definitions hold a lazy `target: Closure(): Mapping` and `assign: Closure(entity, related): entity`. The `target` factory is called once, on first use, and its mapping is remembered for the owner mapping. `assign` can be left out of a relation that is only filtered by or used through `links()`; loading such a relation throws `LogicException` before SQL. In PHPDoc it is `callable(TOwner, ?TTarget): TOwner` for to-one and `callable(TOwner, list<TTarget>): TOwner` for to-many. `assign` returns the original object after a setter, or a new immutable model. For to-one the argument is an object or NULL, for to-many a list, possibly empty.

Relation definitions live in the `Solo\Repository\Relations` namespace (plural).

For `BelongsTo` the `foreignKey` is on the owner; for `HasOne/HasMany` it is on the target table. In both cases the reference points to a primary key. `BelongsToMany` takes `pivotTable`, `foreignPivotKey` and `relatedPivotKey`; a UNIQUE on the key pair and both foreign keys are required. A pivot with additional attributes is modelled as a separate repository.

`HasMany` and `BelongsToMany` accept an optional `orderBy`, the default order of the collection for every load (images by `position`, for example). A `Load` with its own non-empty `orderBy` replaces it.

```php
$query = $query->with([
    'category',
    new Load('tags', criteria: ['enabled' => true], orderBy: ['name' => 'ASC']),
]);
```

`Load(name, criteria = [], orderBy = [], visibility = Visibility::Active)` holds the settings of one relation. `with(['category.parent'])` means a finite load path, not a recursive walk of the graph. Conflicting descriptions of one path and unknown relations are errors. A Load condition limits the loaded collection; it **does not filter owners**. Owners are selected with `Filter::related`, and its conditions do not change the content of `with()` automatically.

`Load` also takes a full path, such as `Load('category.parent', ...)`. Strings create the structure of a path; an explicit Load sets the settings of one edge. So `with([new Load('category', visibility: Visibility::All), 'category.parent'])` includes a deleted category, but its parent stays Active. Different explicit settings for the same exact path are a conflict.

The loader runs one query for to-one and has-many relations and two for many-to-many: the pivot, then the related models. That is the budget per path and per key batch, not a promise of two queries at any volume. Large IN lists are split with the parameter budget and already bound parameters in mind. A translation JOIN adds no separate round trip.

Integer keys are compared by value: a `BelongsTo` target is selected by the unique foreign keys, hydrated once and passed to all owners as one object; `HasOne/HasMany` are selected by `fk IN (...)` without joining the owners. String keys are matched in SQL by a JOIN with the owner table, so equality follows the database collation; each distinct target is still hydrated once.

The batch unit of many-to-many is the owners. The target SELECT is limited by an EXISTS over the pivot and the batch of owner IDs, not by a list of all target IDs. All targets of one owner are sorted in one SQL query; the loader walks that result in database order and distributes it through the pivot. Concatenating independently sorted target chunks, or sorting in PHP instead of by the database collation, is not used.

Owners are selected and limited before collections are loaded. Results are matched by typed row keys. A non-empty `Load.orderBy` sets the order of each collection's items; without it the order is not guaranteed. `HasOne` requires the foreign key to be unique in the target table; several matches are a data error. A limit per parent, lazy loading, cross-connection relations and automatic cascades are not part of 1.2.

Relations that were not loaded stay as the hydrator left them. A loaded to-one without a visible target is assigned as NULL. One PHP instance per row across queries is not guaranteed.

## Soft delete

`softDeleteColumn` must be part of `Mapping.columns` with a nullable datetime type. Reads add IS NULL by default. `Visibility::All` removes the restriction and `Visibility::Deleted` adds IS NOT NULL; without soft delete `Active/All` are equivalent, and `Deleted` is a query configuration error.

`withVisibility()` changes only the root. Relations and relation predicates use `Active` by default; `Load.visibility` and `Filter::related(..., visibility: ...)` override it explicitly. A translation JOIN has no soft delete of its own.

The visibility condition is added outside the user's group: `visible AND (criteria)`. Naming `deleted_at` in the criteria does not turn it off. That is why `not(related('tags', []))` means the absence of **visible** tags.

`update/updateBy` change only active rows; changing the soft-delete column through a patch is forbidden. `delete/deleteBy` set a UTC timestamp on active rows only; deleting again returns 0 and keeps the earlier timestamp. Without soft delete these methods delete physically. `restore(id)` clears the timestamp of a deleted row only and requires soft delete to be configured. `purge(id)` and `purgeBy(criteria)` delete rows physically regardless of visibility; an empty `purgeBy` criterion is rejected, as for `deleteBy`. Cleanup of translations and pivots on physical deletion is defined by the application's FKs and migrations.

## Writes and transactions

The write contract of `Repository<T>`:

```php
insert(array $data): int;                              // affected rows
insertGetId(array $data): int|string;                  // the ID, not affected rows
insertMany(array $rows, int $batchSize = 500): int;     // affected rows
upsert(array $key, array $data, array $onInsert = []): void;
update(int|string $id, array $patch): int;
updateBy(array|Filter $criteria, array $patch): int;
delete(int|string $id): int;
deleteBy(array|Filter $criteria): int;
restore(int|string $id): int;
purge(int|string $id): int;
purgeBy(array|Filter $criteria): int;
lockForUpdate(int|string|array $ids): void;            // inside a transaction only
translations(): TranslationStore;
links(string $relation): LinkSet;                      // BelongsToMany only
```

`insert` and `insertGetId` each perform an insert: do not call both for one record. With `generatedId: false` the ID is required. With `generatedId: true` a given ID is allowed, or an integer identity/sequence generates one. UUIDs generated by the database are not part of this contract; an application UUID is passed explicitly.

An explicit ID is allowed only where the database schema allows it. The package adds no override for GENERATED ALWAYS and does not resynchronize a sequence after a manual insert.

On MySQL with `generatedId: true` an explicit zero ID is rejected: without NO_AUTO_VALUE_ON_ZERO the server may replace it with a new key. To have a key generated, leave the ID out.

`insertGetId` uses the given ID or the platform's verified strategy and returns the key converted by its mapping type. PostgreSQL gets the key through INSERT RETURNING, MySQL and SQLite through the driver's last-insert-ID support. A possible extra round trip for the ID is not a re-read of the model; there is no promise of "always one SQL statement" here.

A patch does not change omitted fields; NULL writes SQL NULL. Values are checked against the column type before SQL, by the same rules as criteria values: `update($id, ['quantity' => 'abc'])` throws instead of writing 0. The primary key cannot be changed. An empty patch returns 0 without SQL. `updateBy/deleteBy` accept only base columns and logical groups; relation and translation predicates are rejected, so that no portable UPDATE/DELETE with JOINs is promised.

An empty criterion, or one that simple rules fold to true, is rejected for bulk writes before the soft-delete condition is added. This guards against an empty filter; it does not prove any logical expression safe. Changing a whole table on purpose is written directly with DBAL.

`insertMany` takes rows with one set of fields; key order does not matter. Different sets are rejected before the first write. An empty list returns 0. Chunks respect the parameter limit, and the operation is atomic: a batch that fits one statement runs as that statement without a transaction, and a split batch runs in one transaction or savepoint. Like a failed `insert`, such a batch that fails inside the caller's transaction on PostgreSQL aborts that transaction; only a split batch rolls back to its own savepoint. The returned affected count depends on the driver: 0 from an UPDATE does not prove that no row exists.

`upsert(key, data, onInsert)` writes the row of a unique key in one native statement: `ON CONFLICT` on PostgreSQL and SQLite, `ON DUPLICATE KEY UPDATE` on MySQL and MariaDB. A new row gets `key + data + onInsert`; an existing one updates only the `data` columns, and the key and other columns stay as they were. `onInsert` holds what only a new row needs: an explicit ID, a creation time. With an empty `data` an existing row is not changed. The key is non-empty, has no NULL and names the columns of one unique index or the primary key: PostgreSQL and SQLite use it as the conflict target and reject a key without such an index, while MySQL and MariaDB update the row on a conflict with any unique index of the table and insert another row when no index covers the key. Each column appears in the three arrays once; `data` does not change the primary key, and none of them holds the soft-delete column. A new row is checked as in `insert`, so with `generatedId: false` the ID goes into `key` or `onInsert`. The row of the key is updated even when it is soft-deleted, and stays deleted; an explicit `restore` brings it back. Platforms count the affected rows of an upsert differently, so the method returns nothing: read the row back by its key.

`lockForUpdate(ids)` locks the physical rows with these primary keys until the end of the current transaction with `SELECT ... FOR UPDATE`; outside a transaction the call is rejected. IDs are normalized and deduplicated, and rows are locked in key order, so competing transactions do not deadlock each other. Large sets are split into statements of 5,000 keys; integer keys are sorted before splitting, so the order holds across statements, while string keys follow the database collation within each statement. SQLite has no row locks and runs a plain SELECT. Cross-process advisory locks ("one critical section per order") stay in the application.

Several operations are combined with DBAL's standard `$db->transactional(...)` on the shared connection. A single CRUD call, an upsert and an `insertMany` that fits one statement open no extra transaction. An `insertMany` split into several statements and `LinkSet` operations use a DBAL transaction or nested savepoint and propagate errors. There are no automatic retries. An application service that must not receive the connection takes the same boundary through `TransactionManager::run()` on the shared connection.

`LinkSet` provides `attach(id, ids): void`, `detach(id, ids): void` and `sync(id, ids): void`. Input IDs are normalized by the type of the target's primary key and deduplicated; their order is not kept. Attaching an existing link and detaching a missing one are no-ops; `sync` sets the exact set, and `sync(id, [])` clears it. Attaching or detaching an empty list does nothing.

A sync reads every physical pivot row of the owner, including references to hidden soft-deleted targets. Display filters do not become write filters. For string IDs, LinkSet also reads the canonical keys through SQL, so that attach, detach and sync follow the database collation and different spellings of one ID. FK errors are not hidden. A pair that another writer stored after the operation read the set is skipped instead of becoming a unique-key error: the INSERT carries `ON CONFLICT (foreignPivotKey, relatedPivotKey) DO NOTHING` on PostgreSQL and SQLite, which therefore need the required UNIQUE on the pair, and `ON DUPLICATE KEY UPDATE` on MySQL and MariaDB, where a conflict with any unique index of the pivot counts as that pair. The transaction guarantees a rollback of a partial change but does not serialize concurrent syncs and attaches. Concurrent attaches of one pair succeed on MySQL and on PostgreSQL in READ COMMITTED. A transaction whose snapshot is older than the other writer's commit is refused by MariaDB with snapshot isolation (the default since 11.6) with error 1020, and by PostgreSQL in REPEATABLE READ and SERIALIZABLE with a serialization failure; two syncs of one set can still interleave. When several processes must arrive at one set, the application takes the owner's lock (`lockForUpdate()` as the transaction's first statement) or another strategy for concurrent writes in a shared transaction.

## Large reads and complex SQL

`iterateById` batches by keyset: `id > lastId`, `ORDER BY id ASC`, `LIMIT batchSize`. It needs one immutable DBAL integer/bigint key, takes no other ordering and uses neither OFFSET nor a preliminary COUNT. The batch size must be positive.

Filters, locale, visibility and includes are the same for every batch. Memory holds the current owners **and their related rows**; an unbounded fan-out can be large. Finished DBAL results are released, and no history of objects is kept. What the consumer itself keeps is not limited by the library.

This is not a server-side cursor and not a snapshot under concurrent changes. For a consistent export the application chooses the transaction and isolation of its database. For an arbitrary order, a composite cursor, raw streamed rows and reports, use DBAL.

`ReadQuery` has no `toDbal()`: changing an exported builder would create a second contract for aliases, projections and policies. A complex query is built on the injected connection and may reuse the same hydrator. Soft delete, translations, type conversion and relations are set explicitly in such a query.

## Platforms and errors

The verified matrix is PostgreSQL 17 (`pdo_pgsql`), MySQL 8.4 and MariaDB 12.3 (`pdo_mysql`) and SQLite (`pdo_sqlite`). CI checks the minimum DBAL 4.3 branch and the latest compatible release. MariaDB is handled as MySQL; SQL Server and other platforms are not declared compatible just because DBAL has a driver for them, and compatibility with older server versions is not claimed.

The SQL builder, quoting, parameters, types and transactions are delegated to DBAL. Narrow operations without a suitable common API check the platform explicitly: generated IDs, upserts and the LIKE escape character. There is no "treat everything unknown as PostgreSQL" branch and no general INSERT IGNORE that would hide unrelated constraint violations.

Errors in mapping definitions and unsupported query parameters are detected before SQL as far as possible; lazy relations are checked when first resolved. DBAL execution errors keep their cause. Invalid results of a hydrator or `assign` are contract errors, not skipped models.

## Verified behavior

Integration scenarios check a catalog with categories and tags, translations, page limits, atomic writes and iteration by ID. They check the independence of locale and includes, same-row/OR/NOT EXISTS predicates, soft delete, projections, NULL and empty translations, typed keys, batch splitting, rollback and an `assign` that returns an immutable model.

For a catalog with category and tags within one batch, the tests check 4 SELECTs: owners, category, pivot, tags. For an UPDATE by ID, one UPDATE without a SELECT. When keyset iteration moves to the next page, the tests check that the objects of the finished batch are released. Performance in milliseconds depends on data, platform and schema and is not a promise of an advantage over an ORM.

## Built on DBAL

The design builds on existing DBAL APIs: [QueryBuilder](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.3/reference/query-builder.html), [parameters and data retrieval](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.3/reference/data-retrieval-and-manipulation.html), [types](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.3/reference/types.html) and [transactions](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.3/reference/transactions.html). The minimum supported and the current DBAL 4.x releases are both checked.
