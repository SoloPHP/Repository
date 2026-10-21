<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Doctrine\DBAL\Types\Types;

/**
 * Key type families: primary and foreign keys compare only within one family.
 *
 * @internal
 */
enum KeyType
{
    case Integer;
    case String;

    public static function of(string $type): ?self
    {
        return match ($type) {
            Types::SMALLINT, Types::INTEGER, Types::BIGINT => self::Integer,
            Types::STRING, Types::ASCII_STRING, Types::GUID => self::String,
            default => null,
        };
    }
}
