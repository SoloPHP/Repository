# Changelog

## 1.0.0 — Unreleased

Initial release of **Solo Repository** (`solophp/repository`) with the
`Solo\Repository` namespace.

### Features

- Explicit, typed `Mapping` and `Repository`; constructing repositories and
  queries never opens a database connection. An application can extend
  `Repository` into a typed repository per table; its public methods are final,
  and minor releases may add new ones.
- Reusable `ReadQuery` with projection reads, `sum`/`min`/`max`/`avg` aggregates
  over the same criteria as `count`, bounded integer-ID iteration, and
  `queryBuilder()`, a DBAL `QueryBuilder` over the rows its criteria select.
- Mappings without a primary key for tables with composite keys; their rows are
  read and changed by criteria. A mapping read only as rows needs no hydrator.
- `Filter::all`, `any`, `not`, and related `EXISTS` predicates.
- Criteria values and written values must have a form of their field type before
  SQL, so a driver cannot silently turn `'12x'` into 12 or `'false'` into TRUE.
  Column ranges, precision and scale stay database constraints.
- One translation fallback rule for reading, filtering, and sorting; an
  optional default locale; native PATCH upserts, insert-missing `seed()` and
  `all()` locales of one owner through `TranslationStore`.
- Four relation types in `Solo\Repository\Relations`, generic over owner and
  target, with lazy target-`Mapping` factories called once per owner mapping,
  nested batch loading, locale inheritance, and explicit per-relation
  visibility and loading criteria. A relation without `assign` can be filtered
  by and linked but not loaded.
  A `BelongsTo` target shared by many owners is fetched and hydrated once;
  `HasMany` and `BelongsToMany` accept a default collection order.
- A bound-parameter budget that follows the platform limit and can be replaced
  per repository with `parameterLimit`; internal key lists stop at 5,000 keys
  per statement. IN lists are bound as one DBAL list parameter.
- Soft delete with `purge`/`purgeBy`, `lockForUpdate` row locks and locking
  reads, native upserts by a unique key, atomic `insertMany`, and many-to-many
  `LinkSet` operations.
- SQLite tests, MySQL 8.4/MariaDB 12.3/PostgreSQL 17 integration tests, and a PHP/DBAL CI matrix.

### Changes since 1.0.0-RC5

- The compatibility rules for subclasses of `Repository` are documented.
  Throughout 1.x it stays a non-final `readonly` class whose public methods,
  except the constructor, are final and whose state is private. A minor release
  may add public methods, and a subclass that declares a method of the same
  name then fails to load. Constructor parameters keep their names and order;
  a parameter added in a minor release is optional and comes last.
- **Behavior change:** `LinkSet::attach()` and `sync()` skip a pair that
  another writer stored after the operation read the set, instead of failing
  on the pivot's unique key. Their INSERT carries `ON CONFLICT (pair) DO NOTHING`
  on PostgreSQL and SQLite and `ON DUPLICATE KEY UPDATE` on MySQL and MariaDB;
  foreign-key violations still propagate. Concurrent attaches of one pair now
  both succeed on MySQL and on PostgreSQL in READ COMMITTED. MariaDB with
  snapshot isolation (error 1020) and PostgreSQL in REPEATABLE READ or
  SERIALIZABLE (a serialization failure) still refuse a transaction whose
  snapshot is older than the other writer's commit, and an exact final set
  still needs an owner lock. On PostgreSQL and SQLite these writes now fail on a
  pivot without the UNIQUE pair that the guide already required.
- A locking read on MySQL tells `FOR UPDATE OF` support by the server version,
  8.0.1 or later, instead of by DBAL's `MySQL80Platform`. DBAL deprecates its
  MySQL 8 platform classes and drops them with MySQL 5.7 support, after which
  `MySQLPlatform` itself stands for MySQL 8; the lock keeps covering only the
  read's table there. MySQL 5.7 and MariaDB keep the plain `FOR UPDATE`. The
  package no longer refers to a deprecated DBAL platform class.

### Changes since 1.0.0-RC4

- `ReadQuery::forUpdate()` returns the same read as a locking one: `find()`,
  `findOneBy()`, `findBy()`, `rows()`, `exists()` and `iterateById()` run
  `SELECT ... FOR UPDATE` and lock the rows they read until the transaction
  ends, and throw `LogicException` outside a transaction. Unlike
  `lockForUpdate()` followed by a plain read, which a REPEATABLE READ
  transaction answers from its snapshot, a locking read never returns an older
  version of a row: MySQL reads the latest committed one, while PostgreSQL and
  MariaDB report a serialization failure. PostgreSQL and MySQL 8 lock only the
  rows of the read's table (`FOR UPDATE OF`); MariaDB also locks the rows the
  statement joins. Relation loads read without locks, and aggregates and
  `queryBuilder()` of a locking read throw `LogicException`.
- `orderBy` of `findBy()`, `findOneBy()` and `rows()` accepts dotted paths
  through `BelongsTo` relations to a field of the target, such as
  `category.name`. Each target is LEFT JOINed once per statement over the rows
  a load of the same relation in the read sees (its active rows without one),
  and translated target fields follow the locale of the query. Default relation
  orders and `Load` orders take the same paths, resolved from the target mapping.
- `Repository::upsert($key, $data, $onInsert = [])` inserts the row of a unique
  key, or updates the row that already holds it, in one native statement:
  `ON CONFLICT` on PostgreSQL and SQLite, `ON DUPLICATE KEY UPDATE` on MySQL
  and MariaDB. `$onInsert` values go only into a new row.
- **Behavior change:** `insertMany()` runs a batch that fits one statement
  without a transaction or savepoint; only a batch split into several
  statements is wrapped in one. Inside a caller's transaction on PostgreSQL, a
  failure of such a batch now aborts that transaction, as a failed `insert()`
  does, instead of rolling back to a savepoint the caller could continue from.

### Changes since 1.0.0-RC3

- `Repository` is no longer final. An application can extend it with a
  `readonly` class that fixes the mapping in its own constructor, so a
  container injects each table's repository by type and static analysis reads
  the model from `@extends Repository<Model>`. Every public method except the
  constructor is final: a subclass adds methods and cannot change the
  inherited reads and writes.

### Changes since 1.0.0-RC2

- `IN` and `NOT IN` criteria and the key lists of relation loads and
  `lockForUpdate()` are bound as one DBAL list parameter (`ArrayParameterType`)
  of values converted through the field type, instead of one named parameter
  per value. DBAL expands the list into the same SQL when it runs the statement.
  A type without a DBAL list binding, such as boolean, still binds each value,
  and the parameter budget still counts every value of a list.
- **Behavior change:** a `queryBuilder()` with IN criteria holds a list
  parameter. Run the builder itself, or pass `getParameterTypes()` along with
  `getParameters()` when its SQL becomes part of another statement.
- The `hydrate` callback of a `Mapping` is optional, and `Mapping::$hydrate` is
  nullable. A mapping without one is read through `rows()`, aggregates,
  `queryBuilder()` and related filters, and written as usual; `find()`,
  `findOneBy()`, `findBy()`, `iterateById()` and loading it as a relation
  target throw `LogicException` before SQL.

### Changes since 1.0.0-RC1

- `ReadQuery::queryBuilder($alias, $criteria)` returns a DBAL `QueryBuilder`
  with the mapped table under the alias in FROM, the criteria and visibility in
  WHERE, and the translation joins the criteria need. It selects nothing; the
  caller adds the SELECT, joins and grouping, and adds conditions with
  `andWhere()`, because `where()` replaces the criteria and visibility.
- `Mapping::$primaryKey` may be NULL. Operations by ID then throw before SQL,
  translations and `HasOne`/`HasMany`/`BelongsToMany` relations of that owner
  are rejected, and `BelongsTo`/`BelongsToMany` targets must have a key.
- `Translations::$defaultLocale` is optional. Without it, a read that names no
  locale joins no translations and returns NULL translated fields; conditions,
  sorts and aggregates on translated fields then throw `LogicException`.
- `TranslationStore::all($id)` returns the translations of every locale of one
  owner, keyed by locale in ascending order.
- **Behavior change:** `save()` and `seed()` insert only the given fields into
  a new translation row, so omitted fields take their column defaults instead
  of NULL. `save()` still keeps the omitted fields of an existing row.
- The `assign` callback of a relation is optional. Loading a relation without
  one throws `LogicException` before SQL.
- A relation's target factory runs once per owner mapping instead of on every
  load, filter and link set.
- Reads without relation loads hydrate rows directly, every read releases rows
  as it goes, relation batches reuse one prepared target read, and
  `insertMany()` no longer copies its rows.

### Migrating from Base Repository

The original `solophp/base-repository` package keeps its v3 API and version
history. Solo Repository uses composition instead of inheritance, moves reads
to `ReadQuery`, and makes write results and query state explicit. It does not
provide a compatibility adapter or replace the old Composer package.

See the [migration guide](docs/guide/migration.md) for construction, criteria,
translations, relations, pagination, and transaction changes.

### Requirements

- PHP ^8.3 and Doctrine DBAL ^4.3, with the appropriate PDO driver.
- One integer or string primary key; relation keys reference primary keys.
- Application-managed FK/UNIQUE constraints and compatible key collations.
- Supported test matrix: SQLite, MySQL 8.4, MariaDB 12.3, PostgreSQL 17. Other DBAL
  platforms are not included in this release's compatibility claim.
