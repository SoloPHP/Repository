<?php

declare(strict_types=1);

namespace Solo\Repository\Examples;

// Example models are kept together for readability.
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Solo\Repository\Filter;
use Solo\Repository\Load;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsTo;
use Solo\Repository\Relations\BelongsToMany;
use Solo\Repository\Repository;
use Solo\Repository\Translations;
use Solo\Repository\Visibility;

/**
 * Примеры API Solo Repository; каталог запускается также из relations.php.
 * Функции ниже — независимые примеры, не запускаются при подключении файла.
 * Схема БД, Connection и транзакционные границы принадлежат приложению.
 */

final readonly class Category
{
    public function __construct(public int $id, public ?string $name)
    {
    }
}

final readonly class Tag
{
    public function __construct(public int $id, public string $slug, public ?string $name)
    {
    }
}

// Обычная модель: приватные свойства не мешают загрузке связей.
final class Product
{
    private ?Category $category = null;

    /** @var list<Tag> */
    private array $tags = [];

    public function __construct(
        public readonly int $id,
        public readonly string $sku,
        public readonly string $price,
        public readonly ?string $name,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self($row['id'], $row['sku'], $row['price'], $row['name']);
    }

    public function setCategory(?Category $category): self
    {
        $this->category = $category;
        return $this;
    }

    /** @param list<Tag> $tags */
    public function setTags(array $tags): self
    {
        $this->tags = $tags;
        return $this;
    }

    public function category(): ?Category
    {
        return $this->category;
    }

    /** @return list<Tag> */
    public function tags(): array
    {
        return $this->tags;
    }
}

/**
 * Связи ссылаются на Mapping цели: загрузка идёт через Connection владельца.
 *
 * @return Repository<Product>
 */
function productRepository(Connection $db): Repository
{
    $categories = new Mapping(
        table: 'categories',
        columns: [
            'id' => Types::INTEGER,
            'deleted_at' => Types::DATETIME_IMMUTABLE,
        ],
        hydrate: static fn(array $row): Category => new Category($row['id'], $row['name']),
        translations: new Translations(
            table: 'category_translations',
            foreignKey: 'category_id',
            columns: ['name' => Types::STRING],
            defaultLocale: 'en',
        ),
        softDeleteColumn: 'deleted_at',
    );

    $tags = new Mapping(
        table: 'tags',
        columns: [
            'id' => Types::INTEGER,
            'slug' => Types::STRING,
            'enabled' => Types::BOOLEAN,
            'deleted_at' => Types::DATETIME_IMMUTABLE,
        ],
        hydrate: static fn(array $row): Tag => new Tag($row['id'], $row['slug'], $row['name']),
        translations: new Translations(
            table: 'tag_translations',
            foreignKey: 'tag_id',
            columns: ['name' => Types::STRING],
            defaultLocale: 'en',
        ),
        softDeleteColumn: 'deleted_at',
    );

    return new Repository(
        connection: $db,
        mapping: new Mapping(
            table: 'products',
            primaryKey: 'id',
            generatedId: true,
            columns: [
                'id' => Types::INTEGER,
                'category_id' => Types::INTEGER,
                'sku' => Types::STRING,
                'price' => Types::DECIMAL,
                'status' => Types::STRING,
                'deleted_at' => Types::DATETIME_IMMUTABLE,
            ],
            hydrate: Product::fromRow(...),
            translations: new Translations(
                table: 'product_translations',
                foreignKey: 'product_id',
                columns: ['name' => Types::STRING, 'description' => Types::TEXT],
                defaultLocale: 'en',
            ),
            softDeleteColumn: 'deleted_at',
            relations: [
                'category' => new BelongsTo(
                    target: static fn(): Mapping => $categories,
                    foreignKey: 'category_id',
                    assign: static fn(Product $product, ?Category $category): Product =>
                        $product->setCategory($category),
                ),
                'tags' => new BelongsToMany(
                    target: static fn(): Mapping => $tags,
                    pivotTable: 'product_tags',
                    foreignPivotKey: 'product_id',
                    relatedPivotKey: 'tag_id',
                    assign: static fn(Product $product, array $tags): Product =>
                        $product->setTags($tags),
                ),
            ],
        ),
    );
}

/**
 * Каталог: один из связанных тегов должен иметь slug=sale И enabled=true.
 * Выводим все enabled-теги товара, а не только совпавший sale-тег.
 * Поле name фильтруется и сортируется по тому же fallback, что видит пользователь.
 *
 * @param Repository<Product> $products
 * @return list<Product>
 */
function catalog(Repository $products): array
{
    return $products
        ->withLocale('uk', fallback: 'en')
        ->with([
            'category',
            new Load('tags', criteria: ['enabled' => true], orderBy: ['name' => 'ASC', 'id' => 'ASC']),
        ])
        ->findBy(
            criteria: Filter::all(
                ['status' => 'active', 'price >=' => '50.00'],
                Filter::any(['name LIKE' => 'Стіл%'], ['name LIKE' => 'Table%']),
                Filter::related('tags', ['slug' => 'sale', 'enabled' => true]),
            ),
            orderBy: ['name' => 'ASC', 'id' => 'ASC'],
            limit: 20,
            offset: 0,
        );
}

/** @param Repository<Product> $products */
function independentReads(Repository $products, int $id): array
{
    $uk = $products->withLocale('uk', fallback: 'en')->with(['category', 'tags']);
    $en = $uk->withLocale('en')->with([]);

    return [
        'uk' => $uk->find($id),
        'en' => $en->find($id),
        'default' => $products->read()->find($id), // defaultLocale=en, без includes
        'ukAgain' => $uk->find($id),              // настройки остались прежними
    ];
}

/** @param Repository<Product> $products */
function relationPredicates(Repository $products): array
{
    return $products->read()->findBy(
        Filter::all(
            // Это два EXISTS: товар имеет sale-тег и отдельно new-тег.
            Filter::related('tags', ['slug' => 'sale']),
            Filter::related('tags', ['slug' => 'new']),
            // Нет ни одного видимого disabled-тега.
            Filter::not(Filter::related('tags', ['enabled' => false])),
        ),
        orderBy: ['id' => 'ASC'],
    );
}

/**
 * Запись продукта, переводов и связей атомарна на одном Connection.
 * categoryId и tagIds должны ссылаться на существующие записи.
 *
 * @param Repository<Product> $products
 * @param list<int> $tagIds
 */
function createProduct(
    Connection $db,
    Repository $products,
    int $categoryId,
    array $tagIds,
): int|string {
    return $db->transactional(static function () use ($products, $categoryId, $tagIds): int|string {
        $id = $products->insertGetId([
            'category_id' => $categoryId,
            'sku' => 'TABLE-01',
            'price' => '199.90',
            'status' => 'active',
        ]);
        $products->translations()->save($id, 'en', ['name' => 'Table', 'description' => 'Oak table']);
        $products->translations()->save($id, 'uk', [
            'name' => 'Стіл',
            'description' => null, // описание берётся из en, если чтение задаёт fallback
        ]);
        $products->links('tags')->sync($id, $tagIds);
        return $id; // ни одна операция не перечитывает модель
    });
}

/** @param Repository<Product> $products */
function translationEditor(Repository $products, int $id): ?array
{
    // Отсутствующее поле description не перезаписывается.
    $products->translations()->save($id, 'uk', ['name' => 'Новий стіл']);

    // Пустая строка намеренно остаётся пустой, не включает fallback.
    $products->translations()->save($id, 'uk', ['description' => '']);

    // Редактор получает собственный uk-перевод, а не подставленные en-значения.
    return $products->translations()->get($id, 'uk');
}

/**
 * Вызывается для известного активного товара.
 * Конкурирующие изменения одного набора tags согласует приложение.
 *
 * @param Repository<Product> $products
 * @param list<int> $tagIds
 */
function updateProduct(Connection $db, Repository $products, int $id, array $tagIds): void
{
    $db->transactional(static function () use ($products, $id, $tagIds): void {
        $products->update($id, ['sku' => 'TABLE-02']);
        $products->translations()->save($id, 'uk', ['name' => 'Стіл 2']);
        $products->links('tags')->sync($id, $tagIds);
    });

    // Для ответа API при необходимости явно выполняется:
    // $products->withLocale('uk', fallback: 'en')->with(['tags'])->find($id);
}

/** @param Repository<Product> $products */
function adminRead(Repository $products, int $id): ?Product
{
    return $products
        ->read()
        ->withVisibility(Visibility::All)
        ->with([new Load('tags', visibility: Visibility::All)])
        ->find($id);
}

/**
 * @param Repository<Product> $products
 * @return list<array{id: int, sku: string, name: ?string}>
 */
function partialRows(Repository $products): array
{
    return $products->withLocale('uk', fallback: 'en')->rows(
        columns: ['id', 'sku', 'name'],
        criteria: ['status' => 'active'],
        orderBy: ['id' => 'ASC'],
        limit: 100,
    );
}

/**
 * Потоковая обработка моделей пакетами. Потребитель не накапливает весь результат.
 * Это keyset batching, не snapshot и не серверный cursor.
 *
 * @param Repository<Product> $products
 * @param resource $output Открытый приложением поток для CSV
 */
function exportProducts(Repository $products, $output): void
{
    foreach (
        $products->withLocale('uk', fallback: 'en')->iterateById(
            criteria: ['status' => 'active'],
            batchSize: 500,
        ) as $product
    ) {
        fputcsv($output, [$product->id, $product->sku, $product->name], ',', '"', '');
    }
}

/**
 * Отчёт с GROUP BY остаётся обычным DBAL-запросом.
 * Soft delete задаётся явно; результат — отдельная проекция, без Product mapper.
 * Параметры типов DBAL не преобразуют автоматически типы возвращённых строк.
 */
function stockReport(Connection $db): array
{
    return $db->createQueryBuilder()
        ->select('p.category_id', 'COUNT(*) AS product_count')
        ->from('products', 'p')
        ->where('p.deleted_at IS NULL')
        ->andWhere('p.status = :status')
        ->setParameter('status', 'active', ParameterType::STRING)
        ->groupBy('p.category_id')
        ->orderBy('p.category_id', 'ASC')
        ->executeQuery()
        ->fetchAllAssociative();
}
