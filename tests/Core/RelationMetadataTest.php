<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testLazyFactoriesAllowCircularRepositories(): void
    {
        $owner = null;
        $target = null;
        $calls = 0;
        $owner = new Repository($this->db, new Mapping(
            'owners',
            ['id' => Types::INTEGER, 'target_id' => Types::INTEGER],
            static fn (array $row): object => (object) $row,
            relations: ['target' => new BelongsTo(
                static function () use (&$target, &$calls): Repository {
                    $calls++;
                    return $target;
                },
                'target_id',
                static fn (object $entity, mixed $related): object => $entity,
            )],
        ));
        $target = new Repository($this->db, new Mapping(
            'targets',
            ['id' => Types::INTEGER],
            static fn (array $row): object => (object) $row,
            relations: ['owners' => new HasMany(
                static function () use (&$owner): Repository {
                    return $owner;
                },
                'target_id',
                static fn (object $entity, mixed $related): object => $entity,
            )],
        ));

        self::assertSame(0, $calls);
        self::assertSame($target, $owner->mapping()->relation('target')->resolve($this->db, $owner->mapping()));
        self::assertSame(1, $calls);
        self::assertSame($owner, $target->mapping()->relation('owners')->resolve($this->db, $target->mapping()));
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
        $target = new Repository($this->db, new Mapping(
            'targets',
            ['id' => $primaryType],
            static fn (array $row): object => (object) $row,
            generatedId: false,
        ));
        $relation = new BelongsTo(
            static fn (): Repository => $target,
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
        self::assertSame($target, $relation->resolve($this->db, $owner));
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
        $target = new Repository($this->db, new Mapping(
            'targets',
            ['id' => Types::INTEGER],
            static fn (array $row): object => (object) $row,
            translations: new Translations('target_translations', 'target_id', ['owner_id' => Types::STRING], 'en'),
        ));
        $relation = new $class(
            static fn (): Repository => $target,
            'owner_id',
            static fn (object $entity, mixed $related): object => $entity,
        );
        $owner = new Mapping('owners', ['id' => Types::INTEGER], static fn (array $row): object => (object) $row);

        $this->expectException(InvalidArgumentException::class);
        $relation->resolve($this->db, $owner);
    }

    public static function targetFkRelations(): iterable
    {
        yield [HasOne::class];
        yield [HasMany::class];
    }

    public function testFactoriesMustReturnRepositoriesOnTheSameConnection(): void
    {
        $other = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $mapping = new Mapping('items', ['id' => Types::INTEGER], static fn (array $row): object => (object) $row);
        $target = new Repository($other, $mapping);
        $relation = new BelongsToMany(
            static fn (): Repository => $target,
            'pivot',
            'owner_id',
            'target_id',
            static fn (object $entity, mixed $related): object => $entity,
        );

        $this->expectException(InvalidArgumentException::class);
        try {
            $relation->resolve($this->db, $mapping);
        } finally {
            $other->close();
        }
    }

    public function testInvalidFactoryResultIsRejected(): void
    {
        $mapping = new Mapping('items', ['id' => Types::INTEGER], static fn (array $row): object => (object) $row);
        $relation = new HasMany(
            static fn (): object => new \stdClass(),
            'owner_id',
            static fn (object $entity, mixed $related): object => $entity,
        );

        $this->expectException(UnexpectedValueException::class);
        $relation->resolve($this->db, $mapping);
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
        $relation->resolve($this->db, $mapping);
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

    public function testForeignKeyRejectsSqlFragments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HasOne(
            static fn (): never => throw new \RuntimeException('not resolved'),
            'owner_id OR 1=1',
            static fn (object $entity, mixed $related): object => $entity,
        );
    }
}
