# Changelog

## 1.0.0 — 2026-10-27

First stable release of **Solo Repository** (`solophp/repository`) with the
`Solo\Repository` namespace.

### Features

- Explicit, typed `Mapping` and `Repository`; constructing repositories and
  queries never opens a database connection. An application can extend
  `Repository` into a typed repository per table; its public methods are final,
  and minor releases may add new ones.
- Reusable `ReadQuery` with projection reads, `sum`/`min`/`max`/`avg` aggregates
  over the same criteria as `count`, bounded integer-ID iteration, and
  `queryBuilder()`, a DBAL `QueryBuilder` over the rows its criteria select.
- Ordering by fields of `BelongsTo` targets through dotted paths such as
  `category.name`, in reads, default relation orders and `Load` orders.
- Locking reads with `forUpdate()`, which never return an older version of a
  row, next to `lockForUpdate()` row locks.
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
  by and linked but not loaded. A `BelongsTo` target shared by many owners is
  fetched and hydrated once; `HasMany` and `BelongsToMany` accept a default
  collection order.
- A bound-parameter budget that follows the platform limit and can be replaced
  per repository with `parameterLimit`; internal key lists stop at 5,000 keys
  per statement. IN lists are bound as one DBAL list parameter.
- Soft delete with `purge`/`purgeBy`, native upserts by a unique key, atomic
  `insertMany`, and many-to-many `LinkSet` operations that skip a pair another
  writer stored first.
- SQLite tests, MySQL 8.4/MariaDB 12.3/PostgreSQL 17 integration tests, and a
  PHP/DBAL CI matrix.

### Requirements

- PHP ^8.3 and Doctrine DBAL ^4.3, with the appropriate PDO driver.
- At most one primary key column, integer or string; relation keys reference
  primary keys.
- Application-managed FK/UNIQUE constraints and compatible key collations.
- Supported test matrix: SQLite, MySQL 8.4, MariaDB 12.3, PostgreSQL 17. Other
  DBAL platforms are not included in this release's compatibility claim.
