# Solo Repository

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/repository.svg)](https://packagist.org/packages/solophp/repository)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Typed PHP repositories on Doctrine DBAL. Map a table to your own objects, read through reusable queries, write explicitly, and get translations with one fallback rule and relations loaded in batches.

## Installation

```bash
composer require solophp/repository
```

Requires PHP 8.3+, Doctrine DBAL ^4.3 and the PDO driver of your database. Tested on MySQL 8.4, MariaDB 12.3, PostgreSQL 17 and SQLite.

## Example

```php
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
$products->update($id, ['price' => '59.90']);

$product = $products->read()->find($id);
$page = $products->read()->findBy(['price >=' => '50.00'], ['price' => 'ASC', 'id' => 'ASC'], limit: 20);
```

`hydrate` receives values already converted by their DBAL types and may build an immutable model of your own. The columns of the mapping are the only fields a read or a write accepts.

## Documentation

**[Full Documentation](https://solophp.github.io/Repository/)**

- [Getting Started](https://solophp.github.io/Repository/guide/)
- [Typed Repositories](https://solophp.github.io/Repository/guide/api#typed-repositories)
- [Mapping and Types](https://solophp.github.io/Repository/guide/api#mapping-and-types)
- [Read Queries](https://solophp.github.io/Repository/guide/api#repository-and-read-queries)
- [Criteria and Filter](https://solophp.github.io/Repository/guide/api#criteria-and-filter)
- [LIKE Patterns](https://solophp.github.io/Repository/guide/api#like-patterns)
- [Translations](https://solophp.github.io/Repository/guide/api#translation-metadata-and-locale)
- [Relations](https://solophp.github.io/Repository/guide/api#relations-and-load)
- [Writes](https://solophp.github.io/Repository/guide/api#repository-writes)
- [Transactions and DBAL](https://solophp.github.io/Repository/guide/api#transactions-and-the-dbal-escape-hatch)
- [Behavior in Detail](https://solophp.github.io/Repository/guide/details)
- [Changelog](CHANGELOG.md)

## License

MIT. See [LICENSE](LICENSE).
