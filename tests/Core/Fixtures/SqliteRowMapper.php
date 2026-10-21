<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core\Fixtures;

use Doctrine\DBAL\DriverManager;
use Solo\Repository\Internal\Database;
use Solo\Repository\Internal\RowMapper;
use Solo\Repository\Mapping;

/** Row mappers over an in-memory SQLite platform. */
final class SqliteRowMapper
{
    /**
     * @template T of object
     * @param Mapping<T> $mapping
     * @return RowMapper<T>
     */
    public static function for(Mapping $mapping): RowMapper
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        return new RowMapper($mapping, new Database($connection));
    }
}
