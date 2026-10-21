<?php

declare(strict_types=1);

namespace Solo\Repository;

use InvalidArgumentException;
use Solo\Repository\Internal\FilterOperator;
use Solo\Repository\Internal\Identifier;

/**
 * Boolean groups and relation predicates, created only through the static factories.
 * The properties describe the group to the compiler and are not part of the public API.
 */
final readonly class Filter
{
    /**
     * @param non-empty-list<array<string, mixed>|self> $parts
     */
    private function __construct(
        /** @internal */
        public FilterOperator $operator,
        /** @internal */
        public array $parts,
        /** @internal */
        public string $relation = '',
        /** @internal */
        public Visibility $visibility = Visibility::Active,
    ) {
    }

    /** @param array<string, mixed>|self ...$parts */
    public static function all(array|self ...$parts): self
    {
        if ($parts === []) {
            throw new InvalidArgumentException('Filter::all() requires at least one part.');
        }

        return new self(FilterOperator::All, array_values($parts));
    }

    /** @param array<string, mixed>|self ...$parts */
    public static function any(array|self ...$parts): self
    {
        if ($parts === []) {
            throw new InvalidArgumentException('Filter::any() requires at least one part.');
        }

        return new self(FilterOperator::Any, array_values($parts));
    }

    /** @param array<string, mixed>|self $part */
    public static function not(array|self $part): self
    {
        return new self(FilterOperator::Not, [$part]);
    }

    /** @param array<string, mixed>|self $criteria */
    public static function related(
        string $relation,
        array|self $criteria = [],
        Visibility $visibility = Visibility::Active,
    ): self {
        if (!Identifier::isValid($relation)) {
            throw new InvalidArgumentException('A related filter requires a plain relation name.');
        }

        return new self(FilterOperator::Related, [$criteria], $relation, $visibility);
    }
}
