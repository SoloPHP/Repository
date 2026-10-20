<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class DatabaseTestCase extends TestCase
{
    protected Connection $connection;
    protected QueryLog $log;

    /** @var list<string> */
    private array $createdTables = [];

    protected function setUp(): void
    {
        $url = getenv('REPOSITORY_DATABASE_URL');
        if ($url === false || $url === '') {
            throw new RuntimeException('Set REPOSITORY_DATABASE_URL to a dedicated MySQL or PostgreSQL test database.');
        }
        $parameters = (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($url);
        if (!in_array($parameters['driver'] ?? null, ['pdo_mysql', 'pdo_pgsql'], true)) {
            throw new RuntimeException('REPOSITORY_DATABASE_URL must use mysql:// or postgresql://.');
        }
        if (!str_ends_with($parameters['dbname'] ?? '', '_test')) {
            throw new RuntimeException('The integration database name must end in _test.');
        }
        // Exercise real server parameter inference instead of PDO SQL interpolation.
        $parameters['driverOptions'] = [PDO::ATTR_EMULATE_PREPARES => false];
        if ($parameters['driver'] === 'pdo_mysql') {
            $parameters['charset'] = 'utf8mb4';
        }
        $this->log = new QueryLog();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->log)]);
        $this->connection = DriverManager::getConnection($parameters, $configuration);
        $this->connection->executeQuery('SELECT 1')->free();
    }

    protected function createSchema(Schema $schema): void
    {
        $manager = $this->connection->createSchemaManager();
        foreach ($schema->getTables() as $table) {
            if (!str_starts_with($table->getName(), 'repo_')) {
                throw new RuntimeException('Integration fixture tables must use the repo_ prefix.');
            }
            // Never remove pre-existing tables if a database was accidentally reused.
            $manager->createTable($table);
            $this->createdTables[] = $table->getName();
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }
        try {
            while ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $manager = $this->connection->createSchemaManager();
            foreach (array_reverse($this->createdTables) as $table) {
                $manager->dropTable($table);
            }
        } finally {
            $this->connection->close();
        }
    }
}
