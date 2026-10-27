<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use InvalidArgumentException;

/** @internal */
final class OrderBy
{
    /**
     * Fields mapped to upper-case directions. A field is a field name or a dotted relation path
     * to a field of a related mapping, such as "category.name"; reads resolve it.
     *
     * @param array<mixed> $orderBy
     * @return array<string, 'ASC'|'DESC'>
     */
    public static function normalize(array $orderBy): array
    {
        $normalized = [];
        foreach ($orderBy as $field => $direction) {
            if (!is_string($field) || !Identifier::isPath($field)) {
                throw new InvalidArgumentException('Order must use field names or relation paths to fields.');
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
