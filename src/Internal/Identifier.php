<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use InvalidArgumentException;

/**
 * Plain SQL identifiers: the only names the package quotes into SQL.
 *
 * @internal
 */
final class Identifier
{
    public const string PATTERN = '[A-Za-z_][A-Za-z0-9_]*';

    public static function isValid(string $name): bool
    {
        return preg_match('/\A' . self::PATTERN . '\z/', $name) === 1;
    }

    public static function assert(string $name): void
    {
        if (!self::isValid($name)) {
            throw new InvalidArgumentException(sprintf('Invalid SQL identifier "%s".', $name));
        }
    }
}
