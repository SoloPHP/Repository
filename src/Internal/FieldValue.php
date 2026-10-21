<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Stringable;

/**
 * Values that a field type binds exactly, in criteria and in written data. Drivers coerce other
 * values without an error: an integer column bound to '1abc' matches or stores 1, and MySQL reads
 * a boolean 'false' as TRUE. Types without a rule go to DBAL unchanged: it rejects mistyped
 * dates, intervals and bcmath numbers itself, while JSON, binary and custom types take their
 * values as they are.
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
            // Plain notation only: an exponent such as '1e999' becomes INF on SQLite.
            Types::DECIMAL => [
                is_int($value)
                    || (is_float($value) && is_finite($value))
                    || (is_string($value) && preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/', $value) === 1),
                'a finite number or a decimal string',
            ],
            Types::FLOAT, Types::SMALLFLOAT => [
                is_int($value)
                    || (is_float($value) && is_finite($value))
                    || (is_string($value) && is_numeric($value) && is_finite((float) $value)),
                'a finite number or a numeric string',
            ],
            Types::BOOLEAN => [in_array($value, [true, false, 0, 1, '0', '1'], true), 'a boolean, 0 or 1'],
            Types::STRING, Types::ASCII_STRING, Types::TEXT, Types::GUID => [
                is_string($value) || is_int($value) || $value instanceof Stringable,
                'a string, an integer or a Stringable',
            ],
            // MySQL reads an integer as a member index, and false would become ''.
            Types::ENUM => [is_string($value) || $value instanceof Stringable, 'a string or a Stringable'],
            // DBAL turns anything but an array into NULL.
            Types::SIMPLE_ARRAY => [is_array($value), 'an array'],
            default => [true, ''],
        };

        if (!$valid) {
            throw new InvalidArgumentException(sprintf('Field "%s" requires %s.', $field, $expected));
        }
    }
}
