<?php

declare(strict_types=1);

namespace Solo\Repository\Relations;

use InvalidArgumentException;
use Solo\Repository\Internal\Identifier;
use Solo\Repository\Mapping;

/**
 * A pivot table references the owner's and the target's primary keys.
 *
 * @template TOwner of object
 * @template TTarget of object
 */
final readonly class BelongsToMany extends Relation
{
    /**
     * @param callable(): Mapping<TTarget> $target
     * @param callable(TOwner, list<TTarget>): TOwner $assign
     * @param array<string, string> $orderBy
     */
    public function __construct(
        callable $target,
        public string $pivotTable,
        public string $foreignPivotKey,
        public string $relatedPivotKey,
        callable $assign,
        array $orderBy = [],
    ) {
        Identifier::assert($pivotTable);
        Identifier::assert($foreignPivotKey);
        Identifier::assert($relatedPivotKey);
        if ($foreignPivotKey === $relatedPivotKey) {
            throw new InvalidArgumentException('Pivot foreign and related key columns must be different.');
        }
        parent::__construct($target, $assign, $orderBy);
    }
}
