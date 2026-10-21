<?php

declare(strict_types=1);

namespace Solo\Repository\Internal\Predicate;

use Solo\Repository\Internal\FilterOperator;

/**
 * AND/OR of two or more parts, or NOT of exactly one part.
 *
 * @internal
 */
final readonly class Group
{
    /** @param non-empty-list<Condition|Group|Exists> $parts */
    public function __construct(public FilterOperator $operator, public array $parts)
    {
    }
}
