<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Closure;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Repository\Filter;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsTo;
use Solo\Repository\Relations\BelongsToMany;
use Solo\Repository\Relations\HasMany;
use Solo\Repository\Relations\HasOne;
use Solo\Repository\Relations\Relation;
use Solo\Repository\Repository;
use Solo\Repository\Translations;
use UnexpectedValueException;

final class RelationMetadataTest extends TestCase
{
    public function testLazyFactoriesAllowCircularMappings(): void
    {
        $owner = null;
        $target = null;
        $calls = 0;
        $owner = new Mapping(
            'owners',
            ['id' => Types::INTEGER, 'target_id' => Types::INTEGER],
            static fn (array $row): object => (object) $row,
            relations: ['target' => new BelongsTo(
                static function () use (&$target, &$calls): Mapping {
                    $calls++;
                    return $target;
                },
                'target_id',
                static fn (object $entity, mixed $related): object => $entity,
            )],
        );
        $target = new Mapping(
            'targets',
            ['id' => Types::INTEGER],
            static fn (array $row): object => (object) $row,
            relations: ['owners' => new HasMany(
                static function () use (&$owner): Mapping {
                    return $owner;
                },
                'target_id',
                static fn (object $entity, mixed $related): object => $entity,
            )],
        );

        self::assertSame(0, $calls);
        self::assertSame($target, $owner->relation('target')->resolve($owner));
        self::assertSame(1, $calls);
        self::assertSame($owner, $target->relation('owners')->resolve($target));
    }

    public function testTargetFactoryRunsOnceForLoadsFiltersAndLinks(): void
    {
        $calls = 0;
        $posts = $this->postsWithTags(
            static function (object $post, array $tags): object {
                $post->tags = $tags;
                return $post;
            },
            $calls,
        );

        $posts->links('tags')->attach(1, [1, 2]);
        self::assertCount(2, $posts->with(['tags'])->find(1)->tags);
        self::assertCount(2, $posts->with(['tags'])->findBy()[0]->tags);
        self::assertTrue($posts->read()->exists(Filter::related('tags', ['id' => 2])));
        self::assertSame(1, $calls);
    }

    public function testRelationWithoutAssignFiltersAndLinksButCannotBeLoaded(): void
    {
        $calls = 0;
        $posts = $this->postsWithTags(null, $calls);

        $posts->links('tags')->attach(1, [2]);
        self::assertTrue($posts->read()->exists(Filter::related('tags', ['id' => 2])));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Relation "tags" has no assign callback to load into.');
        $posts->with(['tags'])->find(1);
    }

    public function testBelongsToRequiresAnOwnersPhysicalColumnBeforeResolution(): void
    {
        $relation = new BelongsTo(
            static fn (): never => throw new \RuntimeException('Factory must stay lazy'),
            'target_id',
            static fn (object $entity, mixed $related): object => $entity,
        );
        $this->expectException(InvalidArgumentException::class);

        new Mapping(
            'owners',
            ['id' => Types::INTEGER],
            static fn (array $row): object => (object) $row,
            translations: new Translations('owner_translations', 'owner_id', ['target_id' => Types::STRING], 'en'),
            relations: ['target' => $relation],
        );
    }

    #[DataProvider('keyTypes')]
    public function testRelatedKeyFamilies(string $foreignType, string $primaryType, bool $compatible): void
    {
        $target = new Mapping(
            'targets',
            ['id' => $primaryType],
            static fn (array $row): object => (object) $row,
            generatedId: false,
        );
        $relation = new BelongsTo(
            static fn (): Mapping => $target,
            'target_id',
            static fn (object $entity, mixed $related): object => $entity,
        );
        $owner = new Mapping(
            'owners',
            ['id' => Types::INTEGER, 'target_id' => $foreignType],
            static fn (array $row): object => (object) $row,
            relations: ['target' => $relation],
        );

        if (!$compatible) {
            $this->expectException(InvalidArgumentException::class);
        }
        self::assertSame($target, $relation->resolve($owner));
    }

    public static function keyTypes(): iterable
    {
        yield 'integer family' => [Types::INTEGER, Types::BIGINT, true];
        yield 'string family' => [Types::ASCII_STRING, Types::GUID, true];
        yield 'different families' => [Types::INTEGER, Types::STRING, false];
        yield 'unsupported FK type' => [Types::BOOLEAN, Types::INTEGER, false];
    }

    #[DataProvider('targetFkRelations')]
    public function testTargetForeignKeyCannotBeAProjectedTranslation(string $class): void
    {
        $target = new Mapping(
            'targets',
            ['id' => Types::INTEGER],
            static fn (array $row): object => (object) $row,
            translations: new Translations('target_translations', 'target_id', ['owner_id' => Types::STRING], 'en'),
        );
        $relation = new $class(
            static fn (): Mapping => $target,
            'owner_id',
            static fn (object $entity, mixed $related): object => $entity,
        );
        $owner = new Mapping('owners', ['id' => Types::INTEGER], static fn (array $row): object => (object) $row);

        $this->expectException(InvalidArgumentException::class);
        $relation->resolve($owner);
    }

    public static function targetFkRelations(): iterable
    {
        yield [HasOne::class];
        yield [HasMany::class];
    }

    #[DataProvider('invalidTargets')]
    public function testFactoriesMustReturnMappings(object $result): void
    {
        $mapping = new Mapping('items', ['id' => Types::INTEGER], static fn (array $row): object => (object) $row);
        $relation = new BelongsToMany(
            static fn (): object => $result,
            'pivot',
            'owner_id',
            'target_id',
            static fn (object $entity, mixed $related): object => $entity,
        );

        $this->expectException(UnexpectedValueException::class);
        $relation->resolve($mapping);
    }

    public static function invalidTargets(): iterable
    {
        $mapping = new Mapping('items', ['id' => Types::INTEGER], static fn (array $row): object => (object) $row);
        yield 'object' => [new \stdClass()];
        yield 'repository' => [
            new Repository(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $mapping),
        ];
    }

    public function testUnsupportedRelationDefinitionIsRejectedBeforeFactoryInvocation(): void
    {
        $relation = new readonly class extends Relation {
            public function __construct()
            {
                parent::__construct(
                    static fn (): never => throw new \RuntimeException('not resolved'),
                    static fn (object $entity, mixed $related): object => $entity,
                );
            }
        };
        $mapping = new Mapping('items', ['id' => Types::INTEGER], static fn (array $row): object => (object) $row);

        $this->expectException(InvalidArgumentException::class);
        $relation->resolve($mapping);
    }

    public function testMappingRejectsUnknownRelation(): void
    {
        $mapping = new Mapping('items', ['id' => Types::INTEGER], static fn (array $row): object => (object) $row);

        $this->expectException(InvalidArgumentException::class);
        $mapping->relation('missing');
    }

    #[DataProvider('invalidRelationMaps')]
    public function testRelationMapMustUseNamesAndDefinitions(array $relations): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Mapping(
            'items',
            ['id' => Types::INTEGER],
            static fn (array $row): object => (object) $row,
            relations: $relations,
        );
    }

    public static function invalidRelationMaps(): iterable
    {
        $relation = new HasMany(
            static fn (): never => throw new \RuntimeException('not resolved'),
            'owner_id',
            static fn (object $entity, mixed $related): object => $entity,
        );
        yield 'list' => [[$relation]];
        yield 'path instead of name' => [['tags.owner' => $relation]];
        yield 'wrong definition' => [['tags' => new \stdClass()]];
    }

    public function testPivotIdentifiersMustBeDistinct(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BelongsToMany(
            static fn (): never => throw new \RuntimeException('not resolved'),
            'pivot',
            'id',
            'id',
            static fn (object $entity, mixed $related): object => $entity,
        );
    }

    #[DataProvider('invalidDefaultOrders')]
    public function testDefaultCollectionOrderMustUsePlainFieldsAndDirections(array $orderBy): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HasMany(
            static fn (): never => throw new \RuntimeException('not resolved'),
            'owner_id',
            static fn (object $entity, array $related): object => $entity,
            $orderBy,
        );
    }

    public static function invalidDefaultOrders(): iterable
    {
        yield 'SQL in direction' => [['position' => 'ASC NULLS LAST']];
        yield 'qualified field' => [['items.position' => 'ASC']];
        yield 'list instead of map' => [['position']];
    }

    public function testDefaultCollectionOrderIsNormalized(): void
    {
        $relation = new BelongsToMany(
            static fn (): never => throw new \RuntimeException('not resolved'),
            'pivot',
            'owner_id',
            'target_id',
            static fn (object $entity, array $related): object => $entity,
            ['position' => 'asc', 'id' => 'DESC'],
        );

        self::assertSame(['position' => 'ASC', 'id' => 'DESC'], $relation->orderBy);
    }

    public function testForeignKeyRejectsSqlFragments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HasOne(
            static fn (): never => throw new \RuntimeException('not resolved'),
            'owner_id OR 1=1',
            static fn (object $entity, mixed $related): object => $entity,
        );
    }

    /** @param int $calls Counts target factory calls. */
    private function postsWithTags(?Closure $assign, int &$calls): Repository
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE posts (id INTEGER PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE tags (id INTEGER PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE post_tag (post_id INTEGER, tag_id INTEGER, UNIQUE(post_id, tag_id))');
        $connection->executeStatement('INSERT INTO posts (id) VALUES (1)');
        $connection->executeStatement('INSERT INTO tags (id) VALUES (1), (2)');

        return new Repository($connection, new Mapping(
            'posts',
            ['id' => Types::INTEGER],
            static fn (array $row): object => (object) $row,
            relations: ['tags' => new BelongsToMany(
                static function () use (&$calls): Mapping {
                    $calls++;
                    return new Mapping('tags', ['id' => Types::INTEGER], static fn (array $row): object => (object) $row);
                },
                'post_tag',
                'post_id',
                'tag_id',
                $assign,
            )],
        ));
    }
}
