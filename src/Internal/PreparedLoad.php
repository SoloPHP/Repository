<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Solo\Repository\Filter;
use Solo\Repository\Relations\Relation;

/** @internal */
final readonly class PreparedLoad
{
    /**
     * @param ReadExecutor<object> $executor Reads the relation target.
     * @param array<string, mixed>|Filter $criteria
     * @param array<string, 'ASC'|'DESC'> $orderBy The Load order, otherwise the relation default.
     * @param list<PreparedLoad> $children
     * @param positive-int $batchSize Owner keys per statement, after the load criteria parameters.
     */
    public function __construct(
        public Relation $relation,
        public ReadExecutor $executor,
        public array|Filter $criteria,
        public array $orderBy,
        public array $children,
        public int $batchSize,
    ) {
    }
}
