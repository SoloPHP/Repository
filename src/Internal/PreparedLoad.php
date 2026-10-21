<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Closure;
use Doctrine\DBAL\Query\QueryBuilder;
use Solo\Repository\Relations\Relation;

/** @internal */
final readonly class PreparedLoad
{
    /**
     * @param Closure $assign The relation's assign callback.
     * @param ReadExecutor<object> $executor Reads the relation target.
     * @param QueryBuilder $query The target read with the load criteria and order; each batch
     *                            clones it and never changes it.
     * @param list<PreparedLoad> $children
     * @param positive-int $batchSize Owner keys per statement, after the parameters of $query.
     */
    public function __construct(
        public Relation $relation,
        public Closure $assign,
        public ReadExecutor $executor,
        public QueryBuilder $query,
        public array $children,
        public int $batchSize,
    ) {
    }
}
