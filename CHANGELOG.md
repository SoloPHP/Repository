# Changelog

## 1.5.0 — 2026-11-10

### Features

- `Filter::related()` takes a dotted path through relations of any kind, such as
  `tags.category`, and nests one related filter per relation. As in a `Load` path,
  the criteria and visibility belong to the last relation.
  See [Criteria and Filter](https://solophp.github.io/Repository/guide/api#criteria-and-filter).

## 1.4.0 — 2026-11-09

### Features

- `ReadQuery::findOrFail()` throws `RecordNotFoundException` when no visible row has
  the ID, also for an ID no row can have, such as `"007"`, which `find()` rejects.
- Read criteria name a field of a `BelongsTo` target by its path, as an order does:
  `['category.name LIKE' => ...]` is a related filter on that target.
- A `Filter` may be the value of a criteria entry; it joins the AND of the array.
  See [Criteria and Filter](https://solophp.github.io/Repository/guide/api#criteria-and-filter).

## 1.3.0 — 2026-11-01

### Features

- `ReadQuery::page()` reads one page of entities and the number of matching rows on
  all pages as a `Page`, for a `PageRequest` of page number, size and order. The
  primary key ends the order, so ties keep their pages, and the total is counted only
  when the page cannot tell it. `Page::of()` applies the counting rule to custom SQL,
  and `PageRequest::orderEndingWith()` the ordering rule. See
  [Pages](https://solophp.github.io/Repository/guide/api#pages).

## 1.2.0 — 2026-10-30

### Features

- `TransactionManager::run()` provides transaction boundaries without exposing a
  SQL connection to services. It shares an application-owned DBAL connection with
  repositories, preserves callback result types, and delegates nested savepoints
  and rollback to DBAL without automatic retries or after-commit callbacks.

## 1.1.0 — 2026-10-28

### Fixes

- `LIKE` and `NOT LIKE` escape with `\` on every supported database, so `\%`,
  `\_` and `\\` match `%`, `_` and `\` themselves. SQLite has no default escape
  character, and under `NO_BACKSLASH_ESCAPES` neither has MySQL, nor MariaDB for
  single-byte character sets, so there `50\%` matched `50\` followed by
  anything. A LIKE, also in `queryBuilder()` SQL, now ends in `ESCAPE '\'` on
  SQLite and in `ESCAPE X'5C'` on MySQL and MariaDB. Both are literals: a bound
  escape character would cost a pattern with a fixed prefix its index range on
  MySQL and SQLite. PostgreSQL, which always escapes with `\`, gets no clause,
  so LIKE on `bytea` fields keeps working.

### Features

- `Like::escape()`, `contains()`, `startsWith()` and `endsWith()` turn plain
  text, such as a search term, into a LIKE pattern that matches it literally.

### Behavior changes

- On SQLite, and on MySQL and MariaDB under `NO_BACKSLASH_ESCAPES`, a `\` in a
  LIKE pattern escapes the character after it instead of matching a backslash:
  the pattern `C:\dir` now matches `C:dir`, and a backslash is written `\\`, as
  on PostgreSQL and by default on MySQL and MariaDB. Patterns without `\` match
  as before.
- A LIKE pattern that ends in an unpaired `\` throws `InvalidArgumentException`
  before SQL. PostgreSQL already rejected it, while MySQL, MariaDB and SQLite
  matched a trailing backslash.

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
