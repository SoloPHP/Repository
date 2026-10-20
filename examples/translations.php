<?php

declare(strict_types=1);

// Run after composer install: php examples/translations.php
require dirname(__DIR__) . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use Solo\Repository\Mapping;
use Solo\Repository\Repository;
use Solo\Repository\Translations;

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('PRAGMA foreign_keys = ON');
$db->executeStatement('CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, sku TEXT NOT NULL)');
$db->executeStatement(
    'CREATE TABLE product_translations ('
    . 'product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE, '
    . 'locale VARCHAR(12) NOT NULL, name VARCHAR(255), description TEXT, '
    . 'UNIQUE (product_id, locale))',
);

/** @var Repository<stdClass> $products */
$products = new Repository(
    connection: $db,
    mapping: new Mapping(
        table: 'products',
        columns: ['id' => Types::INTEGER, 'sku' => Types::STRING],
        hydrate: static fn(array $row): stdClass => (object) $row,
        translations: new Translations(
            table: 'product_translations',
            foreignKey: 'product_id',
            columns: ['name' => Types::STRING, 'description' => Types::TEXT],
            defaultLocale: 'en',
        ),
    ),
);
$translations = $products->translations();

$chairId = $db->transactional(static function () use ($products, $translations): int|string {
    $id = $products->insertGetId(['sku' => 'CHAIR']);
    $translations->save($id, 'en', ['name' => 'Chair', 'description' => 'Wooden chair']);
    $translations->save($id, 'uk', ['name' => null, 'description' => 'Дерев’яний стілець']);

    return $id;
});
$lampId = $products->insertGetId(['sku' => 'LAMP']);
$translations->save($lampId, 'en', ['name' => 'Lamp']);
$translations->save($lampId, 'uk', ['name' => '']); // An intentional empty value, not a fallback request.

$localized = $products->withLocale('uk', fallback: 'en');
$matches = $localized->rows(['id', 'name', 'description'], ['name LIKE' => 'Cha%'], ['name' => 'ASC']);
if ($matches !== [['id' => $chairId, 'name' => 'Chair', 'description' => 'Дерев’яний стілець']]) {
    throw new RuntimeException('Projection and filtering must resolve the same translated value.');
}
echo json_encode($matches, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;

$translations->save($chairId, 'uk', ['name' => 'Стілець']); // Preserve the existing description.
$ownTranslation = $translations->get($chairId, 'uk'); // No fallback in the editing API.
if ($ownTranslation !== ['name' => 'Стілець', 'description' => 'Дерев’яний стілець']) {
    throw new RuntimeException('Saving a patch must preserve omitted fields.');
}

foreach ($localized->iterateById(batchSize: 1) as $product) {
    echo $product->sku . ': ' . json_encode($product->name, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
$translations->delete($chairId, 'uk');
echo 'After deleting uk: ' . $localized->find($chairId)->name . PHP_EOL;
$db->close();
