<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core\Fixtures;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;

/**
 * Counts driver connections. Like the MySQL and PostgreSQL drivers, the wrapped driver
 * asks the server for its version to choose the platform.
 */
final class ConnectCounter implements Middleware
{
    public int $connections = 0;

    public function wrap(Driver $driver): Driver
    {
        $counter = $this;

        return new class ($driver, $counter) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly ConnectCounter $counter)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): DriverConnection
            {
                $this->counter->connections++;

                return parent::connect($params);
            }

            public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
            {
                $versionProvider->getServerVersion();

                return parent::getDatabasePlatform($versionProvider);
            }
        };
    }
}
