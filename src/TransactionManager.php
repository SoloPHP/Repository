<?php

declare(strict_types=1);

namespace Solo\Repository;

use Closure;
use Doctrine\DBAL\Connection;

/**
 * Transaction boundaries over one application-owned connection, shared by all participants.
 * DBAL owns nesting and rollback; the manager never retries the operation or closes the connection.
 */
final readonly class TransactionManager
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Runs once, returning the operation's result after DBAL commits or releases its savepoint.
     * The callback receives no arguments. A successful nested run is not an outer commit.
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public function run(Closure $operation): mixed
    {
        return $this->connection->transactional(static fn () => $operation());
    }
}
