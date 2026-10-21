<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use InvalidArgumentException;

/** @internal */
final class OrderBy
{
    /**
     * Plain field names mapped to upper-case directions.
     *
     * @param array<mixed> $orderBy
     * @return array<string, 'ASC'|'DESC'>
     */
    public static function normalize(array $orderBy): array
    {
        $normalized = [];
        foreach ($orderBy as $field => $direction) {
            if (!is_string($field) || !Identifier::isValid($field)) {
                throw new InvalidArgumentException('Order must use plain field names.');
            }
            $direction = is_string($direction) ? strtoupper($direction) : null;
            if ($direction !== 'ASC' && $direction !== 'DESC') {
                throw new InvalidArgumentException('Order direction must be ASC or DESC.');
            }
            $normalized[$field] = $direction;
        }

        return $normalized;
    }
}
