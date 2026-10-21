<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Repository\Internal\FieldValue;
use Stringable;

final class FieldValueTest extends TestCase
{
    #[DataProvider('acceptedValues')]
    public function testValuesInTheTypeFormAreAccepted(string $type, mixed $value): void
    {
        FieldValue::assert('field', $type, $value);
        $this->addToAssertionCount(1);
    }

    public static function acceptedValues(): iterable
    {
        yield 'integer' => [Types::INTEGER, 5];
        yield 'canonical integer string' => [Types::INTEGER, '-12'];
        yield 'bigint beyond the PHP integer range' => [Types::BIGINT, '9223372036854775808'];
        yield 'decimal string' => [Types::DECIMAL, '-0012.50'];
        yield 'decimal integer' => [Types::DECIMAL, 3];
        yield 'decimal float' => [Types::DECIMAL, 2.5];
        yield 'float exponent' => [Types::FLOAT, '1e3'];
        yield 'smallfloat' => [Types::SMALLFLOAT, 0.5];
        yield 'boolean string' => [Types::BOOLEAN, '0'];
        yield 'stringable text' => [Types::TEXT, self::stringable()];
        yield 'enum string' => [Types::ENUM, 'active'];
        yield 'enum stringable' => [Types::ENUM, self::stringable()];
        yield 'simple array' => [Types::SIMPLE_ARRAY, ['a', 'b']];
        yield 'decimal range is left to the database' => [Types::DECIMAL, str_repeat('9', 309)];
        yield 'smallint range is left to the database' => [Types::SMALLINT, 40000];
        yield 'date is left to DBAL' => [Types::DATETIME_IMMUTABLE, '2026-01-01'];
        yield 'json is left to DBAL' => [Types::JSON, false];
    }

    #[DataProvider('coercedValues')]
    public function testValuesTheDriverWouldCoerceAreRejected(string $type, mixed $value, string $expected): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "field" requires ' . $expected . '.');
        FieldValue::assert('field', $type, $value);
    }

    public static function coercedValues(): iterable
    {
        $decimal = 'a finite number or a decimal string';
        yield 'decimal exponent' => [Types::DECIMAL, '1e999', $decimal];
        yield 'decimal small exponent' => [Types::DECIMAL, '1e3', $decimal];
        yield 'decimal whitespace' => [Types::DECIMAL, ' 1.5', $decimal];
        yield 'decimal infinity' => [Types::DECIMAL, INF, $decimal];
        $float = 'a finite number or a numeric string';
        yield 'float overflow string' => [Types::FLOAT, '1e999', $float];
        yield 'float negative overflow string' => [Types::FLOAT, '-1e999', $float];
        yield 'smallfloat not a number' => [Types::SMALLFLOAT, NAN, $float];
        yield 'float letters' => [Types::FLOAT, 'abc', $float];
        $enum = 'a string or a Stringable';
        yield 'enum false' => [Types::ENUM, false, $enum];
        yield 'enum member index' => [Types::ENUM, 1, $enum];
        yield 'simple array string' => [Types::SIMPLE_ARRAY, 'a,b', 'an array'];
        yield 'integer float' => [Types::INTEGER, 1.0, 'an integer or a canonical decimal string'];
        yield 'boolean word' => [Types::BOOLEAN, 'false', 'a boolean, 0 or 1'];
        yield 'string boolean' => [Types::GUID, true, 'a string, an integer or a Stringable'];
    }

    private static function stringable(): Stringable
    {
        return new class implements Stringable {
            public function __toString(): string
            {
                return 'active';
            }
        };
    }
}
