---
layout: home

hero:
  name: Solo Repository
  text: Your models. Explicit queries.
  tagline: Typed PHP repositories with consistent translations and batch-loaded relations.
  image:
    src: /logo.svg
    alt: Solo Repository
  actions:
    - theme: brand
      text: Get started
      link: /guide/
    - theme: alt
      text: API reference
      link: /guide/api

features:
  - icon: 🧩
    title: Explicit mapping
    details: Map an existing table to ordinary PHP objects. Values are converted before your hydrator runs; partial reads return typed rows.
  - icon: 🔎
    title: Reusable queries
    details: Branch independent ReadQuery settings for locale, visibility, and includes. Group criteria with Filter, select related owners with EXISTS, and search with LIKE patterns that escape the same way on every database.
  - icon: ✍️
    title: Explicit writes
    details: CRUD returns affected rows or a requested ID. Atomic bulk inserts and pivot writes join the application's transactions.
  - icon: 🌐
    title: Consistent translations
    details: Per-field fallback uses the same value in SELECT, WHERE, and ORDER BY. Translation editing has a separate PATCH upsert API.
  - icon: 🔗
    title: Batch relations
    details: Load four relation types and nested paths in batches. Assign related objects to mutable or immutable models without lazy proxies.
  - icon: 🛠️
    title: SQL when you need it
    details: Use the application's Connection for aggregates, custom SQL, locks, and transaction boundaries. Schema and migrations remain explicit.
---

<style>
:root {
  --vp-home-hero-name-color: transparent;
  --vp-home-hero-name-background: linear-gradient(135deg, #06b6d4 0%, #8b5cf6 100%);
  --vp-home-hero-image-background-image: linear-gradient(135deg, #06b6d430 0%, #8b5cf630 100%);
  --vp-home-hero-image-filter: blur(44px);
}

.VPHero .VPImage {
  max-width: 200px;
  max-height: 200px;
}
</style>

## Map, write, and read

The application owns its schema and supplies `$db`, a DBAL `Connection`.
For an existing `products` table with a generated integer `id`, `sku`, and
decimal `price`:

```php
use Doctrine\DBAL\Types\Types;
use Solo\Repository\Mapping;
use Solo\Repository\Repository;

/** @var Repository<stdClass> $products */
$products = new Repository($db, new Mapping(
    table: 'products',
    columns: ['id' => Types::INTEGER, 'sku' => Types::STRING, 'price' => Types::DECIMAL],
    hydrate: static fn(array $row): stdClass => (object) $row,
));

$id = $products->insertGetId(['sku' => 'CHAIR', 'price' => '49.90']);
$products->update($id, ['price' => '59.90']);
$product = $products->read()->find($id);

$page = $products->read()->findBy(
    criteria: ['price >=' => '50.00'],
    orderBy: ['price' => 'ASC', 'id' => 'ASC'],
    limit: 20,
    offset: 0,
);
```

Replace the object cast with your model constructor. Writes do not reread a
model automatically; `rows()` supports projections without hydration. Read the
[getting-started guide](./guide/index.md) and [API reference](./guide/api.md) for
translations, relations, visibility, and transaction contracts.

## Installation

PHP **8.3+**, Doctrine DBAL **^4.3**, and the matching PDO driver are required.
The integration matrix covers SQLite, MySQL 8.4, MariaDB 12.3, and PostgreSQL 17.

```sh
composer require solophp/repository
```

Continue with the [getting-started guide](./guide/index.md).
