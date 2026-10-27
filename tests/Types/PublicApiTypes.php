<?php

declare(strict_types=1);

// Analysed by PHPStan at the highest level as consumer code (phpstan.neon); never executed.
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, PSR1.Files.SideEffects.FoundWithSymbols

namespace Solo\Repository\Tests\Types;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsTo;
use Solo\Repository\Relations\BelongsToMany;
use Solo\Repository\Relations\HasMany;
use Solo\Repository\Relations\HasOne;
use Solo\Repository\Repository;
use Solo\Repository\Visibility;

use function PHPStan\Testing\assertType;

final class Category
{
}

final class Product
{
    public ?Category $category = null;

    /** @var list<Category> */
    public array $categories = [];

    public function withCategory(?Category $category): self
    {
        $copy = clone $this;
        $copy->category = $category;

        return $copy;
    }

    /** @param list<Category> $categories */
    public function withCategories(array $categories): self
    {
        $copy = clone $this;
        $copy->categories = $categories;

        return $copy;
    }
}

/** @return Mapping<Category> */
function categoryMapping(): Mapping
{
    return new Mapping(
        table: 'categories',
        columns: ['id' => Types::INTEGER, 'product_id' => Types::INTEGER],
        hydrate: static fn (array $row): Category => new Category(),
    );
}

/** @param Repository<object> $repository */
function acceptsAnyRepository(Repository $repository): void
{
}

function relationsAcceptTypedAssignCallbacks(Connection $connection): void
{
    $categories = categoryMapping();
    $products = new Mapping(
        table: 'products',
        columns: ['id' => Types::INTEGER, 'category_id' => Types::INTEGER],
        hydrate: static fn (array $row): Product => new Product(),
        relations: [
            'category' => new BelongsTo(
                target: static fn (): Mapping => $categories,
                foreignKey: 'category_id',
                assign: static fn (Product $product, ?Category $category): Product => $product->withCategory($category),
            ),
            'primary' => new HasOne(
                target: static fn (): Mapping => $categories,
                foreignKey: 'product_id',
                assign: static fn (Product $product, ?Category $category): Product => $product->withCategory($category),
            ),
            'children' => new HasMany(
                target: static fn (): Mapping => $categories,
                foreignKey: 'product_id',
                /** @param list<Category> $children */
                assign: static fn (Product $product, array $children): Product => $product->withCategories($children),
                orderBy: ['id' => 'ASC'],
            ),
            'linked' => new BelongsToMany(
                target: static fn (): Mapping => $categories,
                pivotTable: 'product_categories',
                foreignPivotKey: 'product_id',
                relatedPivotKey: 'category_id',
                /** @param list<Category> $linked */
                assign: static fn (Product $product, array $linked): Product => $product->withCategories($linked),
            ),
        ],
    );
    assertType('Solo\Repository\Mapping<Solo\Repository\Tests\Types\Product>', $products);

    $repository = new Repository($connection, $products);
    assertType('Solo\Repository\Repository<Solo\Repository\Tests\Types\Product>', $repository);
    assertType('Solo\Repository\Tests\Types\Product|null', $repository->read()->find(1));
    assertType('list<Solo\Repository\Tests\Types\Product>', $repository->with(['category'])->findBy());
    assertType('iterable<Solo\Repository\Tests\Types\Product>', $repository->withLocale('en')->iterateById());
    assertType(
        'Solo\Repository\ReadQuery<Solo\Repository\Tests\Types\Product>',
        $repository->read()->withVisibility(Visibility::All),
    );

    assertType('float|null', $repository->read()->avg('id'));
    $repository->lockForUpdate([1, '2']);
    assertType(
        'Solo\Repository\ReadQuery<Solo\Repository\Tests\Types\Product>',
        $repository->with(['category'])->forUpdate(),
    );
    assertType('Solo\Repository\Tests\Types\Product|null', $repository->read()->forUpdate()->find(1));
    $repository->upsert(['category_id' => 1], [], ['id' => 2]);

    acceptsAnyRepository($repository);
}

function mappingsWithoutAHydratorReadRows(Connection $connection): void
{
    $links = new Mapping(
        table: 'product_categories',
        columns: ['product_id' => Types::INTEGER, 'category_id' => Types::INTEGER],
        primaryKey: null,
    );
    assertType('Solo\Repository\Mapping<object>', $links);

    $repository = new Repository($connection, $links);
    assertType('list<array<string, mixed>>', $repository->read()->rows(['category_id'], ['product_id IN' => [1, 2]]));

    acceptsAnyRepository($repository);
}

/** @extends Repository<Product> */
final readonly class ProductRepository extends Repository
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection, new Mapping(
            table: 'products',
            columns: ['id' => Types::INTEGER, 'category_id' => Types::INTEGER],
            hydrate: static fn (array $row): Product => new Product(),
        ));
    }
}

function typedRepositoriesKeepTheirModel(Connection $connection): void
{
    $repository = new ProductRepository($connection);
    assertType('Solo\Repository\Tests\Types\Product|null', $repository->read()->find(1));
    assertType('list<Solo\Repository\Tests\Types\Product>', $repository->read()->findBy());

    acceptsAnyRepository($repository);
}

function relationsInferTheirOwnerAndTarget(): void
{
    $categories = categoryMapping();
    $target = static fn (): Mapping => $categories;
    $assign = static fn (Product $product, ?Category $category): Product => $product->withCategory($category);

    assertType(
        'Solo\Repository\Relations\BelongsTo<Solo\Repository\Tests\Types\Product, Solo\Repository\Tests\Types\Category>',
        new BelongsTo($target, 'category_id', $assign),
    );
}
