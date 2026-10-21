<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Closure;
use InvalidArgumentException;
use Solo\Repository\Mapping;
use Solo\Repository\Visibility;

/**
 * The one visibility rule shared by reads, relation predicates and writes.
 *
 * @internal
 */
final class SoftDelete
{
    /** @param Mapping<object> $mapping */
    public static function assertSupported(Mapping $mapping, Visibility $visibility): void
    {
        if ($visibility === Visibility::Deleted && $mapping->softDeleteColumn === null) {
            throw new InvalidArgumentException('Deleted visibility requires a soft-delete column.');
        }
    }

    /**
     * The condition on the soft-delete column, or NULL when every row is visible.
     *
     * @param Mapping<object> $mapping
     * @param Closure(string): string $expression Renders the soft-delete column for this statement.
     */
    public static function condition(Mapping $mapping, Visibility $visibility, Closure $expression): ?string
    {
        if ($mapping->softDeleteColumn === null || $visibility === Visibility::All) {
            return null;
        }

        return $expression($mapping->softDeleteColumn)
            . ($visibility === Visibility::Deleted ? ' IS NOT NULL' : ' IS NULL');
    }
}
