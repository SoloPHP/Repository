# Getting started

Solo Repository maps tables to ordinary PHP objects and provides reusable reads,
explicit writes, translations, and batch-loaded relations. Define a `Mapping`,
compose a `Repository` with a DBAL `Connection`, and query through `ReadQuery`.
Models can be mutable or immutable.

## Install

PHP 8.3+, Doctrine DBAL ^4.3, and the matching PDO driver are required. The test
matrix covers SQLite, MySQL 8.4, MariaDB 12.3, and PostgreSQL 17.

```sh
composer require solophp/repository
```

## Map an existing table

Assume the application has injected `$db`, a `Doctrine\DBAL\Connection`, and
created a `products` table with an integer generated primary key, `sku`, and
`price`:

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
```

Column names form an allowlist. The hydrator receives DBAL-converted values:
`decimal` is a string and `datetime_immutable` is a `DateTimeImmutable`.
Replace the object cast with your model constructor when needed. The package
does not create or inspect your schema automatically.

## Write explicitly

```php
$id = $products->insertGetId(['sku' => 'CHAIR', 'price' => '49.90']);
$affected = $products->update($id, ['price' => '59.90']);
$product = $products->read()->find($id); // Read explicitly when you need a model.
```

`insert`, `insertMany`, `update`, and deletion methods return affected rows.
`insertGetId` returns the primary key. Missing records return `null` from `find`
and `findOneBy`; updates do not throw solely because no record matched.

## Read, filter, and paginate

```php
use Solo\Repository\Filter;
use Solo\Repository\Like;

$query = $products->read();
$page = $query->findBy(
    Filter::any(['price >=' => '100.00'], ['sku IN' => ['CHAIR', 'TABLE']]),
    orderBy: ['price' => 'ASC', 'id' => 'ASC'],
    limit: 20,
    offset: 40,
);
$found = $query->findBy(['sku LIKE' => Like::contains($search)], ['sku' => 'ASC'], limit: 20);
$rows = $query->rows(['id', 'sku'], orderBy: ['id' => 'ASC']);

foreach ($query->iterateById(batchSize: 500) as $product) {
    // Export one model at a time; completed owner batches are released.
}
```

The last pagination argument is a row **offset**, not a page number. Use an
explicit order with a unique final key for stable pages. `iterateById` supports
integer primary keys and does not provide a snapshot across concurrent changes.
`Like::contains()`, `startsWith()` and `endsWith()` turn a search term into a
LIKE pattern that matches it literally, so a `%` or `_` in the term stays a
character on every database.

## Add translations and relations

Configure `Mapping::translations` and `Mapping::relations` before using them:

```php
use Solo\Repository\Load;

$query = $products->withLocale('uk', fallback: 'en')->with([
    'category',
    new Load('tags', criteria: ['enabled' => true], orderBy: ['name' => 'ASC']),
]);
$page = $query->findBy(
    Filter::related('tags', ['slug' => 'sale']),
    orderBy: ['id' => 'ASC'],
    limit: 20,
);
```

Locale and fallback flow into loaded relations and related predicates. Only
`null` or a missing translation triggers fallback; an empty string is a real
translation. `Load::criteria` limits the loaded collection; `Filter::related`
selects owners. One does not implicitly change the other.

The [public API reference](api.md) defines mappings, translation storage, all four
relation types, visibility, and transaction behavior. In the source checkout,
`examples/definitions.php` configures a complete catalog; run
`php examples/relations.php` after `composer install` to check its four-query budget.

## Further reading

- [Public API and constraints](api.md)
- [Behavior in detail and database tests](details.md)
