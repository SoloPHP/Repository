# Changelog

## 1.0.0 — Unreleased

Initial release of **Solo Repository** (`solophp/repository`) with the
`Solo\Repository` namespace.

### Features

- Explicit, typed `Mapping` and composition-based `Repository`; constructing
  repositories and queries never opens a database connection.
- Reusable `ReadQuery` with projection reads, `sum`/`min`/`max`/`avg` aggregates
  over the same criteria as `count`, and bounded integer-ID iteration.
- `Filter::all`, `any`, `not`, and related `EXISTS` predicates.
- Criteria values and written values must have a form of their field type before
  SQL, so a driver cannot silently turn `'12x'` into 12 or `'false'` into TRUE.
  Column ranges, precision and scale stay database constraints.
- One translation fallback rule for reading, filtering, and sorting; native
  PATCH upserts, insert-missing `seed()` and `all()` locales of one owner
  through `TranslationStore`.
- Four relation types in `Solo\Repository\Relations`, generic over owner and
  target, with lazy target-`Mapping` factories called once per owner mapping,
  nested batch loading, locale inheritance, and explicit per-relation
  visibility and loading criteria. A relation without `assign` can be filtered
  by and linked but not loaded.
  A `BelongsTo` target shared by many owners is fetched and hydrated once;
  `HasMany` and `BelongsToMany` accept a default collection order.
- A bound-parameter budget that follows the platform limit and can be replaced
  per repository with `parameterLimit`; internal key lists stop at 5,000 keys
  per statement.
- Soft delete with `purge`/`purgeBy`, `lockForUpdate` row locks, transactional
  `insertMany`, and many-to-many `LinkSet` operations.
- SQLite tests, MySQL 8.4/MariaDB 12.3/PostgreSQL 17 integration tests, and a PHP/DBAL CI matrix.

### Changes since 1.0.0-RC1

- `TranslationStore::all($id)` returns the translations of every locale of one
  owner, keyed by locale in ascending order.
- **Behavior change:** `save()` and `seed()` insert only the given fields into
  a new translation row, so omitted fields take their column defaults instead
  of NULL. `save()` still keeps the omitted fields of an existing row.
- The `assign` callback of a relation is optional. Loading a relation without
  one throws `LogicException` before SQL.
- A relation's target factory runs once per owner mapping instead of on every
  load, filter and link set.

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
