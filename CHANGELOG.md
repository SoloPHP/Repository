# Changelog

## 1.0.0 — Unreleased

First release of **Solo Repository** (`solophp/repository`), a separate product
with the `Solo\Repository` namespace and its own version history. This entry
describes release preparation; no tag has been published.

### Features

- Explicit, typed `Mapping` and composition-based `Repository`.
- Reusable `ReadQuery` with projection reads and bounded integer-ID iteration.
- `Filter::all`, `any`, `not`, and related `EXISTS` predicates.
- One translation fallback rule for reading, filtering, and sorting;
  native PATCH upserts through `TranslationStore`.
- Four relation types in `Solo\Repository\Relations`, nested batch loading,
  locale inheritance, and explicit per-relation visibility and loading criteria.
- Soft delete, transactional `insertMany`, and many-to-many `LinkSet` operations.
- SQLite tests, MySQL 8.4/PostgreSQL 17 integration tests, and a PHP/DBAL CI matrix.

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
- Supported test matrix: SQLite, MySQL 8.4, PostgreSQL 17. MariaDB and other DBAL
  platforms are not included in this release's compatibility claim.
