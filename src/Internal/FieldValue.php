<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Stringable;

/**
 * Values that a field type binds exactly, in criteria and in written data. Drivers coerce other
 * values without an error: an integer column bound to '1abc' matches or stores 1, and MySQL reads
 * a boolean 'false' as TRUE. Other types, such as dates, are left to DBAL conversion, which
 * rejects mismatches itself.
 *
 * @internal
 */
final class FieldValue
{
    /** `0` or an optionally negative decimal integer without a plus sign, leading zeros or whitespace. */
    public static function isCanonicalInteger(string $value): bool
    {
        return preg_match('/\A(?:0|-?[1-9][0-9]*)\z/', $value) === 1;
    }

    public static function assert(string $field, string $type, mixed $value): void
    {
        [$valid, $expected] = match ($type) {
            Types::SMALLINT, Types::INTEGER, Types::BIGINT => [
                is_int($value) || (
                    is_string($value)
                    && self::isCanonicalInteger($value)
                    && ($type === Types::BIGINT || filter_var($value, FILTER_VALIDATE_INT) !== false)
                ),
                'an integer or a canonical decimal string',
            ],
            Types::DECIMAL, Types::FLOAT, Types::SMALLFLOAT => [
                is_int($value) || (is_float($value) && is_finite($value)) || (is_string($value) && is_numeric($value)),
                'a number or a numeric string',
            ],
            Types::BOOLEAN => [in_array($value, [true, false, 0, 1, '0', '1'], true), 'a boolean, 0 or 1'],
            Types::STRING, Types::ASCII_STRING, Types::TEXT, Types::GUID => [
                is_string($value) || is_int($value) || $value instanceof Stringable,
                'a string, an integer or a Stringable',
            ],
            default => [true, ''],
        };

        if (!$valid) {
            throw new InvalidArgumentException(sprintf('Field "%s" requires %s.', $field, $expected));
        }
    }
}
