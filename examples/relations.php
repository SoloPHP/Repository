<?php

declare(strict_types=1);

// Run after composer install: php examples/relations.php
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/definitions.php';

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Psr\Log\AbstractLogger;

use function Solo\Repository\Examples\catalog;
use function Solo\Repository\Examples\createProduct;
use function Solo\Repository\Examples\productRepository;

$logger = new class extends AbstractLogger {
    public int $selects = 0;

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql']) && str_starts_with($context['sql'], 'SELECT ')) {
            ++$this->selects;
        }
    }
};
$configuration = new Configuration();
$configuration->setMiddlewares([new Middleware($logger)]);
$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
$db->executeStatement('PRAGMA foreign_keys = ON');
$schema = [
    'CREATE TABLE categories (id INTEGER PRIMARY KEY, deleted_at DATETIME)',
    'CREATE TABLE tags (id INTEGER PRIMARY KEY, slug TEXT, enabled BOOLEAN, deleted_at DATETIME)',
    'CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, '
        . 'category_id INTEGER REFERENCES categories(id), sku TEXT UNIQUE, '
        . 'price DECIMAL(10, 2), status TEXT, deleted_at DATETIME)',
    'CREATE TABLE category_translations (category_id INTEGER REFERENCES categories(id), '
        . 'locale TEXT, name TEXT, UNIQUE(category_id, locale))',
    'CREATE TABLE tag_translations (tag_id INTEGER REFERENCES tags(id), '
        . 'locale TEXT, name TEXT, UNIQUE(tag_id, locale))',
    'CREATE TABLE product_translations (product_id INTEGER REFERENCES products(id), '
        . 'locale TEXT, name TEXT, description TEXT, UNIQUE(product_id, locale))',
    'CREATE TABLE product_tags (product_id INTEGER REFERENCES products(id), '
        . 'tag_id INTEGER REFERENCES tags(id), UNIQUE(product_id, tag_id))',
];
foreach ($schema as $sql) {
    $db->executeStatement($sql);
}
$db->insert('categories', ['id' => 1]);
$db->insert('category_translations', ['category_id' => 1, 'locale' => 'en', 'name' => 'Furniture']);
foreach ([[1, 'sale', 'Sale', 1], [2, 'new', 'New', 1], [3, 'disabled', 'Disabled', 0]] as $tag) {
    [$id, $slug, $name, $enabled] = $tag;
    $db->insert('tags', ['id' => $id, 'slug' => $slug, 'enabled' => $enabled]);
    $db->insert('tag_translations', ['tag_id' => $id, 'locale' => 'en', 'name' => $name]);
}

$products = productRepository($db);
$id = createProduct($db, $products, categoryId: 1, tagIds: [1, 2, 3]);
$logger->selects = 0;
$result = catalog($products);
if (count($result) !== 1 || $logger->selects !== 4) {
    throw new RuntimeException('The catalog must load its page, category, pivot and tags in four SELECTs.');
}
$product = $result[0];
$tagNames = array_map(static fn($tag): ?string => $tag->name, $product->tags());
if ($product->category()?->name !== 'Furniture' || $tagNames !== ['New', 'Sale']) {
    throw new RuntimeException('Relations must inherit the locale/fallback and their own loading criteria.');
}
echo json_encode([
    'name' => $product->name,
    'category' => $product->category()->name,
    'tags' => $tagNames,
    'selects' => $logger->selects,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;

$products->links('tags')->sync($id, [2]);
if (catalog($products) !== []) {
    throw new RuntimeException('Filter::related must exclude a product after its sale link is removed.');
}
foreach ($products->withLocale('uk', 'en')->with(['category', 'tags'])->iterateById(batchSize: 1) as $product) {
    echo $product->sku . ': ' . $product->name . ', tags=' . count($product->tags()) . PHP_EOL;
}
$db->close();
