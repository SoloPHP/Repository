<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Repository\Mapping;
use Solo\Repository\Tests\Core\Fixtures\MappingHydrator;
use Solo\Repository\Tests\Core\Fixtures\MappingValue;
use Solo\Repository\Tests\Core\Fixtures\MappingValueType;
use Solo\Repository\Tests\Core\Fixtures\SqliteRowMapper;
use stdClass;
use UnexpectedValueException;

final class MappingTest extends TestCase
{
    public function testMappingStoresConfigurationAndNormalizesCallable(): void
    {
        $mapping = new Mapping(
            table: 'products',
            columns: ['product_id' => Types::GUID, 'deleted_at' => Types::DATETIME_IMMUTABLE],
            hydrate: [MappingHydrator::class, 'hydrate'],
            primaryKey: 'product_id',
            generatedId: false,
            softDeleteColumn: 'deleted_at',
        );

        self::assertSame('products', $mapping->table);
        self::assertSame('product_id', $mapping->primaryKey);
        self::assertFalse($mapping->generatedId);
        self::assertSame('deleted_at', $mapping->softDeleteColumn);
        self::assertSame(Types::GUID, $mapping->columnType('product_id'));
        self::assertInstanceOf(Closure::class, $mapping->hydrate);
        self::assertSame('product-1', ($mapping->hydrate)(['product_id' => 'product-1'])->product_id);
    }

    #[DataProvider('invalidMetadata')]
    public function testInvalidMetadataIsRejected(array $configuration): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Mapping(...array_replace([
            'table' => 'products',
            'columns' => ['id' => Types::INTEGER],
            'hydrate' => static fn (array $row): object => (object) $row,
        ], $configuration));
    }

    public static function invalidMetadata(): iterable
    {
        yield 'empty table' => [['table' => '']];
        yield 'qualified table' => [['table' => 'public.products']];
        yield 'SQL in table' => [['table' => 'products; DROP TABLE products']];
        yield 'invalid column' => [['columns' => ['id' => Types::INTEGER, 'a.b' => Types::STRING]]];
        yield 'numeric column key' => [['columns' => [Types::INTEGER]]];
        yield 'unregistered type' => [['columns' => ['id' => Types::INTEGER, 'name' => 'repo_missing_type']]];
        yield 'type object instead of name' => [['columns' => ['id' => Type::getType(Types::INTEGER)]]];
        yield 'missing primary key' => [['columns' => ['name' => Types::STRING]]];
        yield 'invalid primary key name' => [['primaryKey' => 'id DESC']];
        yield 'unsupported primary key type' => [['columns' => ['id' => Types::JSON], 'generatedId' => false]];
        yield 'generated string primary key' => [['columns' => ['id' => Types::STRING]]];
        yield 'unknown soft-delete column' => [['softDeleteColumn' => 'deleted_at']];
        yield 'invalid soft-delete identifier' => [['softDeleteColumn' => 'deleted.at']];
        yield 'non-datetime soft-delete column' => [[
            'columns' => ['id' => Types::INTEGER, 'deleted_at' => Types::DATE_IMMUTABLE],
            'softDeleteColumn' => 'deleted_at',
        ]];
    }

    public function testUnknownColumnIsRejected(): void
    {
        $mapping = $this->mapping(['id' => Types::INTEGER]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown mapped column "password"');

        $mapping->columnType('password');
    }

    #[DataProvider('softDeleteTypes')]
    public function testSupportedSoftDeleteTypes(string $type): void
    {
        $mapping = new Mapping(
            table: 'products',
            columns: ['id' => Types::INTEGER, 'deleted_at' => $type],
            hydrate: static fn (array $row): object => (object) $row,
            softDeleteColumn: 'deleted_at',
        );

        self::assertSame($type, $mapping->columnType('deleted_at'));
    }

    public static function softDeleteTypes(): iterable
    {
        yield [Types::DATETIME_MUTABLE];
        yield [Types::DATETIME_IMMUTABLE];
        yield [Types::DATETIMETZ_MUTABLE];
        yield [Types::DATETIMETZ_IMMUTABLE];
    }

    public function testHydrationReceivesDbalConvertedValues(): void
    {
        $mapper = SqliteRowMapper::for($this->mapping([
            'id' => Types::INTEGER,
            'price' => Types::DECIMAL,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'active' => Types::BOOLEAN,
            'attributes' => Types::JSON,
            'deleted_at' => Types::DATETIME_IMMUTABLE,
        ]));

        $product = $mapper->hydrate($mapper->convert([
            'id' => '42',
            'price' => '19.90',
            'created_at' => '2026-10-20 12:34:56',
            'active' => 0,
            'attributes' => '{"sizes":[1,2],"featured":true}',
            'deleted_at' => null,
        ]));

        self::assertSame(42, $product->id);
        self::assertSame('19.90', $product->price);
        self::assertInstanceOf(DateTimeImmutable::class, $product->created_at);
        self::assertSame('2026-10-20 12:34:56', $product->created_at->format('Y-m-d H:i:s'));
        self::assertFalse($product->active);
        self::assertSame(['sizes' => [1, 2], 'featured' => true], $product->attributes);
        self::assertNull($product->deleted_at);
    }

    public function testPartialRowsConvertOnlyPresentMappedFields(): void
    {
        $mapper = SqliteRowMapper::for($this->mapping([
            'id' => Types::INTEGER,
            'price' => Types::DECIMAL,
            'created_at' => Types::DATETIME_IMMUTABLE,
        ]));

        self::assertSame(['price' => '12', 'aggregate' => '3'], $mapper->convert([
            'price' => 12,
            'aggregate' => '3',
        ]));
        self::assertSame([], $mapper->convert([]));
    }

    public function testCustomDbalTypeIsAppliedBeforeHydration(): void
    {
        $typeName = 'repo_mapping_value_object';
        if (!Type::hasType($typeName)) {
            Type::addType($typeName, MappingValueType::class);
        }

        $mapper = SqliteRowMapper::for($this->mapping([
            'id' => Types::INTEGER,
            'code' => $typeName,
        ]));

        $product = $mapper->hydrate($mapper->convert(['id' => '1', 'code' => 'SKU-001']));

        self::assertInstanceOf(MappingValue::class, $product->code);
        self::assertSame('SKU-001', $product->code->value);
        self::assertSame(['code' => null], $mapper->convert(['code' => null]));
    }

    public function testNonObjectHydratorResultIsRejected(): void
    {
        $mapping = new Mapping('products', ['id' => Types::INTEGER], static fn (array $row): array => $row);
        $mapper = SqliteRowMapper::for($mapping);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('hydrator must return an object');

        $mapper->hydrate(['id' => 1]);
    }

    #[DataProvider('validIds')]
    public function testIdsAreNormalizedToTheirMappedType(string $type, int|string $id, int|string $expected): void
    {
        $mapper = SqliteRowMapper::for($this->mapping(['id' => $type], false));

        self::assertSame($expected, $mapper->id($id));
    }

    public static function validIds(): iterable
    {
        yield 'integer' => [Types::INTEGER, '42', 42];
        yield 'negative integer' => [Types::INTEGER, '-42', -42];
        yield 'zero' => [Types::INTEGER, '0', 0];
        yield 'smallint' => [Types::SMALLINT, '42', 42];
        yield 'PHP minimum' => [Types::INTEGER, (string) PHP_INT_MIN, PHP_INT_MIN];
        yield 'PHP maximum' => [Types::INTEGER, (string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'bigint in range' => [Types::BIGINT, '42', 42];
        yield 'bigint beyond PHP range' => [Types::BIGINT, '9223372036854775808', '9223372036854775808'];
        yield 'string' => [Types::STRING, 'sku-42', 'sku-42'];
        yield 'integer to string' => [Types::STRING, 42, '42'];
        yield 'ASCII string' => [Types::ASCII_STRING, '00042', '00042'];
        yield 'GUID' => [Types::GUID, '838c188d-2736-4f12-9f6a-cd35ab0c4b30', '838c188d-2736-4f12-9f6a-cd35ab0c4b30'];
    }

    #[DataProvider('invalidIds')]
    public function testInvalidIdsAreRejectedBeforeCoercion(string $type, string $id): void
    {
        $mapper = SqliteRowMapper::for($this->mapping(['id' => $type], false));

        $this->expectException(InvalidArgumentException::class);

        $mapper->id($id);
    }

    public static function invalidIds(): iterable
    {
        yield 'non-numeric' => [Types::INTEGER, 'foo'];
        yield 'partly numeric' => [Types::INTEGER, '12abc'];
        yield 'fraction' => [Types::INTEGER, '12.5'];
        yield 'leading whitespace' => [Types::INTEGER, ' 12'];
        yield 'trailing newline' => [Types::INTEGER, "12\n"];
        yield 'leading zero' => [Types::INTEGER, '012'];
        yield 'positive sign' => [Types::INTEGER, '+12'];
        yield 'negative zero' => [Types::INTEGER, '-0'];
        yield 'exponent' => [Types::BIGINT, '1e3'];
        yield 'integer overflow' => [Types::INTEGER, '9223372036854775808'];
        yield 'smallint PHP overflow' => [Types::SMALLINT, '9223372036854775808'];
        yield 'empty integer' => [Types::INTEGER, ''];
        yield 'empty string' => [Types::STRING, ''];
    }

    /**
     * @param array<string, string> $columns
     * @return Mapping<stdClass>
     */
    private function mapping(array $columns, bool $generatedId = true): Mapping
    {
        return new Mapping('products', $columns, static fn (array $row): stdClass => (object) $row, 'id', $generatedId);
    }
}
