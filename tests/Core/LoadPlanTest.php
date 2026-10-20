<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Repository\Filter;
use Solo\Repository\Internal\LoadPlan;
use Solo\Repository\Load;
use Solo\Repository\Visibility;

final class LoadPlanTest extends TestCase
{
    #[DataProvider('specOrder')]
    public function testImplicitPathsMergeWithExplicitEdgeSettings(bool $reverse): void
    {
        $spec = [
            'category.parent',
            'category',
            new Load('category', visibility: Visibility::All),
            new Load('category.parent', criteria: ['enabled' => true], orderBy: ['id' => 'desc']),
            'tags',
            'tags',
        ];
        $plan = LoadPlan::from($reverse ? array_reverse($spec) : $spec);
        $category = $plan->nodes['category'];
        $parent = $category->children->nodes['parent'];

        self::assertCount(2, $plan->nodes);
        self::assertSame('category', $category->load->name);
        self::assertSame(Visibility::All, $category->load->visibility);
        self::assertSame('parent', $parent->load->name);
        self::assertSame(['enabled' => true], $parent->load->criteria);
        self::assertSame(['id' => 'DESC'], $parent->load->orderBy);
        self::assertSame(Visibility::Active, $parent->load->visibility);
        self::assertSame([], $parent->children->nodes);
    }

    public static function specOrder(): iterable
    {
        yield [false];
        yield [true];
    }

    public function testIdenticalExplicitFiltersCanBeRepeated(): void
    {
        $plan = LoadPlan::from([
            new Load('tags', Filter::all(['active' => true], ['name !=' => null]), ['id' => 'asc']),
            new Load('tags', Filter::all(['active' => true], ['name !=' => null]), ['id' => 'ASC']),
        ]);

        self::assertCount(1, $plan->nodes);
        self::assertSame(['id' => 'ASC'], $plan->nodes['tags']->load->orderBy);
    }

    public function testConflictingExplicitSettingsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LoadPlan::from([
            new Load('category.parent', visibility: Visibility::All),
            new Load('category.parent', visibility: Visibility::Active),
        ]);
    }

    public function testNullAndEmptyStringCriteriaAreDifferentSettings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LoadPlan::from([
            new Load('tags', ['name' => null]),
            new Load('tags', ['name' => '']),
        ]);
    }

    public function testEquivalentRelatedFiltersCanBeRepeated(): void
    {
        $plan = LoadPlan::from([
            new Load('tags', Filter::related('owner', ['name' => null], Visibility::All)),
            new Load('tags', Filter::related('owner', ['name' => null], Visibility::All)),
        ]);

        self::assertCount(1, $plan->nodes);
    }

    public function testRelatedFilterVisibilityIsPartOfExplicitSettings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LoadPlan::from([
            new Load('tags', Filter::related('owner', [], Visibility::All)),
            new Load('tags', Filter::related('owner')),
        ]);
    }

    public function testAnEmptyPlanHasNoNodes(): void
    {
        self::assertSame([], LoadPlan::from([])->nodes);
    }

    #[DataProvider('invalidSpecs')]
    public function testInvalidLoadSpecificationsAreRejected(array $spec): void
    {
        $this->expectException(InvalidArgumentException::class);

        LoadPlan::from($spec);
    }

    public static function invalidSpecs(): iterable
    {
        yield 'associative list' => [['tags' => 'tags']];
        yield 'unsupported item' => [[null]];
        yield 'empty path' => [['']];
        yield 'leading dot' => [['.tags']];
        yield 'trailing dot' => [['tags.']];
        yield 'empty segment' => [['tags..owner']];
        yield 'whitespace' => [['tags. owner']];
        yield 'wildcard' => [['tags.*']];
    }

    #[DataProvider('invalidOrders')]
    public function testInvalidOrderingFailsBeforeTargetResolution(array $orderBy): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Load('tags', orderBy: $orderBy);
    }

    public static function invalidOrders(): iterable
    {
        yield [['name' => 'ASC NULLS LAST']];
        yield [['name' => true]];
        yield [['category.name' => 'ASC']];
        yield [['name']];
    }
}
