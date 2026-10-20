# Solo Repository

Typed PHP repositories with consistent translation fallback and batch-loaded relations.

Map tables to your own objects, reuse query settings, and write data explicitly.

## Requirements and installation

- PHP **8.3+** and Doctrine DBAL **^4.3**.
- A PDO driver for your database: `pdo_mysql`, `pdo_pgsql`, or `pdo_sqlite`.
- Tested server versions: **MySQL 8.4** and **PostgreSQL 17**, plus SQLite.
  Other DBAL platforms are not automatically supported.

Install from the GitHub repository:

```sh
composer config repositories.solophp-repository vcs https://github.com/SoloPHP/Repository.git
composer require 'solophp/repository:dev-main'
```

## Quick start

The application owns its database schema. This SQLite example creates one in
memory so you can run it after installing the package:

```php
<?php

require 'vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use Solo\Repository\Mapping;
use Solo\Repository\Repository;

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT NOT NULL UNIQUE,
    price DECIMAL(10, 2) NOT NULL
)');

/** @var Repository<stdClass> $products */
$products = new Repository($db, new Mapping(
    table: 'products',
    columns: ['id' => Types::INTEGER, 'sku' => Types::STRING, 'price' => Types::DECIMAL],
    hydrate: static fn(array $row): stdClass => (object) $row,
));

$id = $products->insertGetId(['sku' => 'CHAIR', 'price' => '49.90']);
$products->update($id, ['price' => '59.90']); // Affected rows; no hidden model read.

$query = $products->read();
$product = $query->find($id);              // stdClass|null
$page = $query->findBy(
    criteria: ['price >=' => '50.00'],
    orderBy: ['price' => 'ASC', 'id' => 'ASC'],
    limit: 20,
    offset: 0,
);

foreach ($query->iterateById(batchSize: 100) as $product) {
    echo $product->sku . PHP_EOL;
}
```

`hydrate` receives DBAL-converted values and can construct your own immutable
model instead. `Mapping` defines every readable and writable field; unknown
fields are rejected.

## What it provides

- Reusable `ReadQuery` settings; `withLocale()`, `with()` and `withVisibility()`
  return new queries. Projection reads use `rows()` without hydrating models.
- `Filter::all/any/not/related`, with related predicates compiled as `EXISTS`.
- Field-by-field translation fallback shared by reads, filters, and sorting;
  explicit translation PATCH upserts through `translations()->save()`.
- `BelongsTo`, `HasOne`, `HasMany`, and `BelongsToMany` in the **`Relations`**
  namespace, including nested batch loading and inherited locale/fallback.
- Soft delete with explicit visibility, bounded ID iteration, atomic bulk
  inserts, and `links()->attach/detach/sync()` for physical pivot writes.

Schema migrations, aggregate queries, locks, transaction boundaries across
repositories, and cache invalidation belong to the application and DBAL.
There is no identity map, lazy loading, automatic cascade save, or change tracking.

## Documentation

- [Getting started](docs/guide/index.md)
- [Public API and contracts](docs/guide/api.md)
- [Migration from Base Repository v3](docs/guide/migration.md)
- [Detailed behavior and database tests](docs/guide/details.md)
- [Runnable catalog with translations and relations](examples/relations.php)
- [Changelog](CHANGELOG.md) and [release verification](RELEASING.md)
- [Base Repository v3 documentation](https://github.com/SoloPHP/Base-Repository/tree/v3.1.0/docs)

## Development

```sh
git clone https://github.com/SoloPHP/Repository.git
cd Repository
composer install
composer test
php examples/crud.php
php examples/translations.php
php examples/relations.php
php examples/migration.php
```

`composer test:database` runs the separate MySQL/PostgreSQL suite and requires
`REPOSITORY_DATABASE_URL`. See the [Docker and CI instructions](docs/guide/details.md#проверка-на-mysql-и-postgresql).
CI covers PHP 8.3 with the minimum DBAL 4.3 branch and PHP 8.3/8.5 with the latest
compatible DBAL release.

## License

[MIT](LICENSE).
