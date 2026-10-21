<?php

declare(strict_types=1);

namespace Solo\Repository\Internal\Predicate;

/**
 * One validated field comparison; NULL values compile to IS [NOT] NULL.
 *
 * @internal
 */
final readonly class Condition
{
    public function __construct(
        public string $field,
        public string $operator,
        public mixed $value,
        public string $type,
    ) {
    }
}
