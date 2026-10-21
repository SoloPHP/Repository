<?php

declare(strict_types=1);

namespace Solo\Repository\Relations;

use Solo\Repository\Internal\Identifier;
use Solo\Repository\Mapping;

/**
 * A foreign key of the target references the owner's primary key.
 *
 * @template TOwner of object
 * @template TTarget of object
 */
final readonly class HasMany extends Relation
{
    /**
     * @param callable(): Mapping<TTarget> $target
     * @param (callable(TOwner, list<TTarget>): TOwner)|null $assign NULL when the relation is never loaded.
     * @param array<string, string> $orderBy
     */
    public function __construct(
        callable $target,
        public string $foreignKey,
        ?callable $assign = null,
        array $orderBy = [],
    ) {
        Identifier::assert($foreignKey);
        parent::__construct($target, $assign, $orderBy);
    }
}
