<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * Platforms with native generated-ID and upsert support. MariaDB uses the MySQL dialect.
 *
 * @internal
 */
enum Dialect
{
    case MySQL;
    case PostgreSQL;
    case SQLite;

    public static function of(AbstractPlatform $platform): ?self
    {
        return match (true) {
            $platform instanceof AbstractMySQLPlatform => self::MySQL,
            $platform instanceof PostgreSQLPlatform => self::PostgreSQL,
            $platform instanceof SQLitePlatform => self::SQLite,
            default => null,
        };
    }
}
