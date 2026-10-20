<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

/**
 * Keeps database keys independent from user models and relation assignment.
 *
 * @internal
 * @template T of object
 */
final class Record
{
    /**
     * @param array<string, mixed> $row Converted DBAL values.
     * @param T $entity
     */
    public function __construct(
        public readonly array $row,
        public object $entity,
        public readonly int|string|null $ownerId = null,
    ) {
    }
}
