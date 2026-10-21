<?php

declare(strict_types=1);

namespace Solo\Repository\Internal\Predicate;

use Solo\Repository\Mapping;
use Solo\Repository\Relations\Relation;
use Solo\Repository\Visibility;

/**
 * A related row of the resolved target that also matches the criteria; NULL criteria match any row.
 *
 * @internal
 */
final readonly class Exists
{
    /**
     * @param Mapping<object> $owner
     * @param Mapping<object> $target
     */
    public function __construct(
        public Relation $relation,
        public Mapping $owner,
        public Mapping $target,
        public Condition|Group|Exists|null $criteria,
        public Visibility $visibility,
    ) {
    }
}
