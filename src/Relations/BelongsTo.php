<?php

declare(strict_types=1);

namespace Solo\Repository\Relations;

use Solo\Repository\Internal\Identifier;
use Solo\Repository\Mapping;

/**
 * The owner's foreign key references the target's primary key.
 *
 * @template TOwner of object
 * @template TTarget of object
 */
final readonly class BelongsTo extends Relation
{
    /**
     * @param callable(): Mapping<TTarget> $target
     * @param (callable(TOwner, TTarget|null): TOwner)|null $assign NULL when the relation is never loaded.
     */
    public function __construct(callable $target, public string $foreignKey, ?callable $assign = null)
    {
        Identifier::assert($foreignKey);
        parent::__construct($target, $assign);
    }
}
