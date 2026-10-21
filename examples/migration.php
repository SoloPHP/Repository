<?php

declare(strict_types=1);

namespace Solo\Repository\MigrationExample;

// Run after composer install: php examples/migration.php
require dirname(__DIR__) . '/vendor/autoload.php';

use DateTimeImmutable;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Types\Types;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Solo\Repository\Filter;
use Solo\Repository\Load;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsTo;
use Solo\Repository\Relations\BelongsToMany;
use Solo\Repository\Repository;
use Solo\Repository\Translations;
use Solo\Repository\Visibility;
use Stringable;

final class Product
{
    public ?object $category = null;
    /** @var list<object> */
    public array $tags = [];

    public function __construct(
        public readonly int $id,
        public readonly string $sku,
        public readonly string $price,
        public readonly ?string $name,
        public readonly ?string $description,
        public readonly ?DateTimeImmutable $deletedAt,
    ) {
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

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

// Schema remains the application's responsibility. All relation keys have FKs.
$schema = [
    'CREATE TABLE categories (id INTEGER PRIMARY KEY, deleted_at DATETIME)',
    'CREATE TABLE category_translations (category_id INTEGER NOT NULL REFERENCES categories(id), '
        . 'locale TEXT NOT NULL, name TEXT, UNIQUE(category_id, locale))',
    'CREATE TABLE tags (id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE, '
        . 'enabled BOOLEAN NOT NULL, deleted_at DATETIME)',
    'CREATE TABLE tag_translations (tag_id INTEGER NOT NULL REFERENCES tags(id), '
        . 'locale TEXT NOT NULL, name TEXT, UNIQUE(tag_id, locale))',
    'CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, '
        . 'category_id INTEGER REFERENCES categories(id), sku TEXT NOT NULL UNIQUE, '
        . 'price DECIMAL(10, 2) NOT NULL, status TEXT NOT NULL, deleted_at DATETIME)',
    'CREATE TABLE product_translations (product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE, '
        . 'locale TEXT NOT NULL, name TEXT, description TEXT, UNIQUE(product_id, locale))',
    'CREATE TABLE product_tags (product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE, '
        . 'tag_id INTEGER NOT NULL REFERENCES tags(id), PRIMARY KEY(product_id, tag_id))',
];
foreach ($schema as $sql) {
    $db->executeStatement($sql);
}

$categoryMapping = new Mapping(
    table: 'categories',
    columns: ['id' => Types::INTEGER, 'deleted_at' => Types::DATETIME_IMMUTABLE],
    hydrate: static fn(array $row): object => (object) $row,
    generatedId: false,
    softDeleteColumn: 'deleted_at',
    translations: new Translations('category_translations', 'category_id', ['name' => Types::STRING], 'en'),
);
$tagMapping = new Mapping(
    table: 'tags',
    columns: [
        'id' => Types::INTEGER,
        'slug' => Types::STRING,
        'enabled' => Types::BOOLEAN,
        'deleted_at' => Types::DATETIME_IMMUTABLE,
    ],
    hydrate: static fn(array $row): object => (object) $row,
    generatedId: false,
    softDeleteColumn: 'deleted_at',
    translations: new Translations('tag_translations', 'tag_id', ['name' => Types::STRING], 'en'),
);
$categories = new Repository($db, $categoryMapping);
$tags = new Repository($db, $tagMapping);
$products = new Repository($db, new Mapping(
    table: 'products',
    columns: [
        'id' => Types::INTEGER,
        'category_id' => Types::INTEGER,
        'sku' => Types::STRING,
        'price' => Types::DECIMAL,
        'status' => Types::STRING,
        'deleted_at' => Types::DATETIME_IMMUTABLE,
    ],
    hydrate: static fn(array $row): Product => new Product(
        $row['id'],
        $row['sku'],
        $row['price'],
        $row['name'],
        $row['description'],
        $row['deleted_at'],
    ),
    softDeleteColumn: 'deleted_at',
    translations: new Translations(
        'product_translations',
        'product_id',
        ['name' => Types::STRING, 'description' => Types::TEXT],
        'en',
    ),
    relations: [
        'category' => new BelongsTo(
            static fn(): Mapping => $categoryMapping,
            'category_id',
            static function (Product $product, ?object $category): Product {
                $product->category = $category;
                return $product;
            },
        ),
        'tags' => new BelongsToMany(
            static fn(): Mapping => $tagMapping,
            'product_tags',
            'product_id',
            'tag_id',
            static function (Product $product, array $tags): Product {
                $product->tags = $tags;
                return $product;
            },
        ),
    ],
));

$categories->insert(['id' => 1]);
$categories->translations()->save(1, 'en', ['name' => 'Furniture']);
foreach ([[1, 'sale', 'Sale'], [2, 'new', 'New'], [3, 'hidden', 'Hidden']] as [$tagId, $slug, $name]) {
    $tags->insert(['id' => $tagId, 'slug' => $slug, 'enabled' => true]);
    $tags->translations()->save($tagId, 'en', ['name' => $name]);
}
$tags->delete(3);

// V3 create() + seedTranslations() + attach(): make the whole creation atomic.
$id = $db->transactional(function () use ($products): int|string {
    $id = $products->insertGetId([
        'category_id' => 1, 'sku' => 'CHAIR-1', 'price' => '29.90', 'status' => 'active',
    ]);
    $products->translations()->save($id, 'en', ['name' => 'Chair', 'description' => 'English description']);
    $products->translations()->save($id, 'uk', ['name' => null, 'description' => '']);
    $products->links('tags')->attach($id, [1, 2, 3]);
    return $id;
});
$secondId = $products->insertGetId([
    'category_id' => 1, 'sku' => 'DESK-1', 'price' => '39.90', 'status' => 'active',
]);
$products->translations()->save($secondId, 'en', ['name' => 'Desk']);
$thirdId = $products->insertGetId([
    'category_id' => 1, 'sku' => 'LAMP-1', 'price' => '19.90', 'status' => 'active',
]);
$products->translations()->save($thirdId, 'en', ['name' => 'Lamp']);

// The read settings are reusable and do not mutate the default query.
$default = $products->read();
$uk = $default->withLocale('uk', fallback: 'en');
expect($default->find($id)?->name === 'Chair', 'The default locale must remain English.');
expect($default->withLocale('uk')->find($id)?->name === null, 'Fallback must be explicit.');
expect($uk->find($id)?->name === 'Chair', 'NULL must use the fallback.');
expect($uk->find($id)?->description === '', 'An empty translation must stay empty.');

$criteria = Filter::all(
    ['status' => 'active', 'price >=' => '20.00', 'price <=' => '40.00'],
    Filter::related('tags', ['slug' => 'sale', 'enabled' => true]),
    ['name' => 'Chair'],
);
$catalog = $uk->with([
    'category',
    new Load('tags', criteria: ['enabled' => true], orderBy: ['name' => 'ASC']),
]);
$logger->selects = 0;
$items = $catalog->findBy($criteria, ['id' => 'ASC'], limit: 20, offset: 0);
expect(count($items) === 1 && $items[0]->id === $id, 'The catalog must match the resolved name and one sale tag.');
expect($logger->selects === 4, 'The catalog must use owner, category, pivot and target SELECTs.');
expect($items[0]->category?->name === 'Furniture', 'Loaded relations must inherit fallback.');
expect(array_column($items[0]->tags, 'name') === ['New', 'Sale'], 'The hidden tag must not be loaded.');

// V3 page 2 / perPage 1 becomes offset 1 / limit 1.
$page = 2;
$perPage = 1;
$pageRows = $default->rows(['id'], orderBy: ['id' => 'ASC'], limit: $perPage, offset: ($page - 1) * $perPage);
expect($pageRows === [['id' => $secondId]], 'A page number must be converted to an offset.');
expect($catalog->with([])->rows(['id'], $criteria) === [['id' => $id]], 'Projection must preserve criteria.');

// Writes return counts/IDs, so reread deliberately when the caller needs a model.
$db->transactional(function () use ($products, $id): void {
    expect($products->update($id, ['sku' => 'CHAIR-UPDATED']) === 1, 'The base patch must affect one row.');
    $products->translations()->save($id, 'uk', ['name' => 'Оновлений стілець']);
    $products->links('tags')->sync($id, [2, 3]);
});
$physicalIds = static fn(): array => array_map('intval', $db->fetchFirstColumn(
    'SELECT tag_id FROM product_tags WHERE product_id = ? ORDER BY tag_id',
    [$id],
    [Types::INTEGER],
));
expect($physicalIds() === [2, 3], 'Sync must retain the explicitly requested hidden membership.');
expect($items[0]->sku === 'CHAIR-1', 'A previously hydrated model is not automatically updated.');
expect($uk->find($id)?->sku === 'CHAIR-UPDATED', 'Reread must show the committed base patch.');
$products->links('tags')->detach($id, []);
expect($physicalIds() === [2, 3], 'An empty detach list must do nothing.');

// A late FK failure must roll back base, translation and pivot writes together.
try {
    $db->transactional(function () use ($products, $id): void {
        $products->update($id, ['sku' => 'MUST-ROLL-BACK']);
        $products->translations()->save($id, 'uk', ['name' => 'Must roll back']);
        $products->links('tags')->sync($id, [1, 999999]);
    });
    throw new RuntimeException('The missing tag must cause an FK exception.');
} catch (ForeignKeyConstraintViolationException) {
    expect(!$db->isTransactionActive(), 'The failed outer transaction must have ended.');
}
expect($uk->find($id)?->sku === 'CHAIR-UPDATED', 'The base patch must roll back.');
expect($uk->find($id)?->name === 'Оновлений стілець', 'The translation patch must roll back.');
expect($physicalIds() === [2, 3], 'The complete physical tag set must roll back.');
$products->links('tags')->sync($id, []);
expect($physicalIds() === [], 'sync(id, []) clears all physical memberships.');
$products->links('tags')->attach($id, [2]);

// Visibility no longer uses a criterion sentinel such as deleted_at => '*'.
expect($products->delete($thirdId) === 1, 'Soft delete must affect the active row.');
expect($products->delete($thirdId) === 0, 'Repeating soft delete must be a no-op.');
expect($default->find($thirdId) === null, 'Deleted rows must be hidden by default.');
expect($default->withVisibility(Visibility::Deleted)->find($thirdId) !== null, 'Deleted visibility must expose it.');
expect($products->restore($thirdId) === 1, 'Restore must make the row active again.');
expect($products->purge($thirdId) === 1, 'Purge must delete physically, including configured FK cascades.');
expect($default->find($thirdId) === null, 'A missing record must return NULL.');
expect($products->update($thirdId, ['sku' => 'missing']) === 0, 'Update must return an affected count.');

// Export consumes pages rather than accumulating model objects.
$export = [];
foreach ($uk->with(['tags'])->iterateById(['status' => 'active'], batchSize: 1) as $product) {
    $export[] = ['id' => $product->id, 'name' => $product->name, 'tags' => count($product->tags)];
}
expect(array_column($export, 'id') === [$id, $secondId], 'Keyset export must visit active owners in ID order.');
expect($export[0]['tags'] === 1, 'Export relations must reflect the final committed tag set.');

echo json_encode([
    'migration' => 'ok',
    'catalog_selects' => 4,
    'rollback_verified' => true,
    'export' => $export,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
$db->close();
