<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Solo\Repository\Load;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\Relation;

/** @internal */
final readonly class PreparedLoad
{
    /** @param list<PreparedLoad> $children */
    public function __construct(
        public Relation $relation,
        public Mapping $mapping,
        public ReadExecutor $executor,
        public Load $load,
        public array $children,
        public int $batchSize,
    ) {
    }
}
