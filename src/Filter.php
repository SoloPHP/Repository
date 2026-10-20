<?php

declare(strict_types=1);

namespace Solo\Repository;

use InvalidArgumentException;

final readonly class Filter
{
    /**
     * @param 'AND'|'OR'|'NOT'|'RELATED' $operator
     * @param non-empty-list<array<string, mixed>|self> $parts
     */
    private function __construct(
        public string $operator,
        public array $parts,
        public string $relation = '',
        public Visibility $visibility = Visibility::Active,
    ) {
    }

    /** @param array<string, mixed>|self ...$parts */
    public static function all(array|self ...$parts): self
    {
        if ($parts === []) {
            throw new InvalidArgumentException('Filter::all() requires at least one part.');
        }

        return new self('AND', array_values($parts));
    }

    /** @param array<string, mixed>|self ...$parts */
    public static function any(array|self ...$parts): self
    {
        if ($parts === []) {
            throw new InvalidArgumentException('Filter::any() requires at least one part.');
        }

        return new self('OR', array_values($parts));
    }

    /** @param array<string, mixed>|self $part */
    public static function not(array|self $part): self
    {
        return new self('NOT', [$part]);
    }

    /** @param array<string, mixed>|self $criteria */
    public static function related(
        string $relation,
        array|self $criteria = [],
        Visibility $visibility = Visibility::Active,
    ): self {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $relation) !== 1) {
            throw new InvalidArgumentException('A related filter requires a plain relation name.');
        }

        return new self('RELATED', [$criteria], $relation, $visibility);
    }
}
