<?php

declare(strict_types=1);

// Run from the repository after composer install: php examples/crud.php
require dirname(__DIR__) . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use Solo\Repository\Filter;
use Solo\Repository\Mapping;
use Solo\Repository\Repository;
use Solo\Repository\Visibility;

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement(
    'CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, '
    . 'sku TEXT UNIQUE, price DECIMAL(10, 2), deleted_at DATETIME)',
);

/** @var Repository<stdClass> $items */
$items = new Repository(
    connection: $db,
    mapping: new Mapping(
        table: 'items',
        columns: [
            'id' => Types::INTEGER,
            'sku' => Types::STRING,
            'price' => Types::DECIMAL,
            'deleted_at' => Types::DATETIME_IMMUTABLE,
        ],
        hydrate: static fn(array $row): stdClass => (object) $row,
        softDeleteColumn: 'deleted_at',
    ),
);

$db->transactional(static function () use ($items): void {
    $items->insertMany([
        ['sku' => 'TABLE', 'price' => '199.90'],
        ['sku' => 'CHAIR', 'price' => '49.90'],
        ['sku' => 'LAMP', 'price' => '29.90'],
    ], batchSize: 2);
});

$active = $items->read();
$all = $active->withVisibility(Visibility::All);
$chair = $active->findOneBy(['sku' => 'CHAIR']);
assert($chair instanceof stdClass);
$items->update($chair->id, ['price' => '59.90']);
$items->delete($chair->id);

echo 'Active: ' . $active->count() . '; including deleted: ' . $all->count() . PHP_EOL;

$export = $active->iterateById(
    criteria: Filter::any(['price >=' => '100.00'], ['sku' => 'LAMP']),
    batchSize: 1,
);
foreach ($export as $item) {
    echo $item->id . ': ' . $item->sku . ' = ' . $item->price . PHP_EOL;
}

$items->restore($chair->id);
echo json_encode($active->rows(['id', 'sku'], orderBy: ['id' => 'ASC']), JSON_THROW_ON_ERROR) . PHP_EOL;
$db->close();
