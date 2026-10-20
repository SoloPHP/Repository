# Getting started

Solo Repository maps tables to ordinary PHP objects and provides reusable reads,
explicit writes, translations, and batch-loaded relations. Define a `Mapping`,
compose a `Repository` with a DBAL `Connection`, and query through `ReadQuery`.
Models can be mutable or immutable.

The first `solophp/repository` release, **1.0.0**, is in preparation. For an
application using `solophp/base-repository` v3, start with the
[migration guide](migration.md).

## Install

PHP 8.3+, Doctrine DBAL ^4.3, and the matching PDO driver are required. The test
matrix covers SQLite, MySQL 8.4, and PostgreSQL 17.

For this unpublished checkout, add a local path repository to the consumer's
`composer.json` (adjust `/absolute/path/to/repository`):

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/absolute/path/to/repository",
      "options": {
        "versions": {"solophp/repository": "1.0.x-dev"}
      }
    }
  ]
}
```

Merge this entry into an existing `repositories` list, then install explicitly:

```sh
composer require 'solophp/repository:1.0.x-dev@dev'
```

After `1.0.0` is published, the stable command will be
`composer require 'solophp/repository:^1.0'`.

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

$query = $products->read();
$page = $query->findBy(
    Filter::any(['price >=' => '100.00'], ['sku IN' => ['CHAIR', 'TABLE']]),
    orderBy: ['price' => 'ASC', 'id' => 'ASC'],
    limit: 20,
    offset: 40,
);
$rows = $query->rows(['id', 'sku'], orderBy: ['id' => 'ASC']);

foreach ($query->iterateById(batchSize: 500) as $product) {
    // Export one model at a time; completed owner batches are released.
}
```

The last pagination argument is a row **offset**, not a page number. Use an
explicit order with a unique final key for stable pages. `iterateById` supports
integer primary keys and does not provide a snapshot across concurrent changes.

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
- [Migration from Base Repository v3](migration.md)
- [Detailed behavior and database test commands (Russian)](details.md)
- [Base Repository v3 documentation](https://github.com/SoloPHP/Base-Repository/tree/v3.1.0/docs)
