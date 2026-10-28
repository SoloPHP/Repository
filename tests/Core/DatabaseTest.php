<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\Deprecations\Deprecation;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Repository\Filter;
use Solo\Repository\Internal\Database;
use Solo\Repository\Internal\Dialect;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsToMany;
use Solo\Repository\Repository;
use Solo\Repository\Tests\Core\Fixtures\ConnectCounter;
use Solo\Repository\Translations;
use Solo\Repository\Visibility;

final class DatabaseTest extends TestCase
{
    public function testRepositoriesQueriesAndWriteHelpersDoNotConnectBeforeSql(): void
    {
        $counter = new ConnectCounter();
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            (new Configuration())->setMiddlewares([$counter]),
        );
        $repository = new Repository($connection, $this->productMapping());

        $query = $repository->with(['tags'])->withLocale('uk', 'en')->withVisibility(Visibility::All);
        $repository->links('tags');
        $repository->translations();
        self::assertSame(0, $counter->connections);

        $connection->executeStatement('CREATE TABLE products (id INTEGER PRIMARY KEY)');
        $connection->executeStatement(
            'CREATE TABLE product_translations (product_id INTEGER, locale TEXT, name TEXT)',
        );
        self::assertSame(0, $query->count());
        self::assertSame(1, $counter->connections);
    }

    /** @return iterable<string, array{AbstractPlatform, Dialect|null, int}> */
    public static function platforms(): iterable
    {
        yield 'MySQL' => [new MySQLPlatform(), Dialect::MySQL, 65535];
        yield 'MariaDB' => [new MariaDBPlatform(), Dialect::MySQL, 65535];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), Dialect::PostgreSQL, 65535];
        yield 'unsupported platform' => [new SQLServerPlatform(), null, 900];
    }

    #[DataProvider('platforms')]
    public function testDialectAndDefaultParameterLimitFollowThePlatform(
        AbstractPlatform $platform,
        ?Dialect $dialect,
        int $limit,
    ): void {
        $database = new Database($this->connection($platform));

        self::assertSame($dialect, $database->dialect());
        self::assertSame($limit, $database->parameterLimit());
    }

    /** @return iterable<string, array{string, int}> */
    public static function sqliteVersions(): iterable
    {
        yield 'before 3.32' => ['3.31.1', 900];
        yield 'since 3.32' => ['3.32.0', 32766];
        yield 'current' => ['3.46.1', 32766];
    }

    #[DataProvider('sqliteVersions')]
    public function testSqliteParameterLimitFollowsTheLibraryVersion(string $version, int $limit): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects(self::once())->method('getServerVersion')->willReturn($version);
        $database = new Database($connection);

        self::assertSame(Dialect::SQLite, $database->dialect());
        self::assertSame($limit, $database->parameterLimit());
        self::assertSame($limit, $database->parameterLimit());
    }

    public function testExplicitParameterLimitDoesNotNeedThePlatform(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('getDatabasePlatform');

        self::assertSame(1000, (new Database($connection, 1000))->parameterLimit());
    }

    public function testInternalKeyListsAreCappedWithinTheParameterBudget(): void
    {
        $database = new Database($this->connection(new PostgreSQLPlatform()));

        // Longer IN lists can make MySQL abandon range access and scan, or lock, every row.
        self::assertSame(65535, $database->batchSize());
        self::assertSame(5000, $database->keyBatchSize());
        self::assertSame(5000, $database->keyBatchSize(60000));
        self::assertSame(535, $database->keyBatchSize(65000));
    }

    public function testParameterLimitMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The parameter limit must be positive.');
        new Database($this->createStub(Connection::class), 0);
    }

    public function testIdentifiersAreQuotedAsSingleNames(): void
    {
        $database = new Database($this->connection(new PostgreSQLPlatform()));

        self::assertSame('"order"', $database->quote('order'));
        self::assertSame('"br_base"."select"', $database->qualified('br_base', 'select'));
    }

    /** @return iterable<string, array{AbstractPlatform, string}> */
    public static function likeEscapes(): iterable
    {
        // A hex literal reads as one backslash whether or not MySQL runs with NO_BACKSLASH_ESCAPES.
        yield 'MySQL' => [new MySQLPlatform(), " ESCAPE X'5C'"];
        yield 'MariaDB' => [new MariaDBPlatform(), " ESCAPE X'5C'"];
        // The backslash is PostgreSQL's fixed default, and an ESCAPE literal would break bytea patterns.
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), ''];
        yield 'SQLite' => [new SQLitePlatform(), " ESCAPE '\\'"];
        yield 'unsupported platform' => [new SQLServerPlatform(), " ESCAPE '\\'"];
    }

    #[DataProvider('likeEscapes')]
    public function testLikePatternsEscapeWithABackslashOnEveryPlatform(
        AbstractPlatform $platform,
        string $clause,
    ): void {
        self::assertSame($clause, (new Database($this->connection($platform)))->likeEscape());
    }

    public function testReadsAndWritesUseNoDeprecatedDbalApi(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE products (id INTEGER PRIMARY KEY)');
        $connection->executeStatement(
            'CREATE TABLE product_translations (product_id INTEGER, locale TEXT, name TEXT, '
                . 'UNIQUE(product_id, locale))',
        );
        $connection->executeStatement('CREATE TABLE tags (id INTEGER PRIMARY KEY)');
        $connection->executeStatement(
            'CREATE TABLE product_tag (product_id INTEGER, tag_id INTEGER, UNIQUE(product_id, tag_id))',
        );
        $connection->executeStatement('INSERT INTO tags VALUES (1), (2)');
        $repository = new Repository($connection, $this->productMapping());

        Deprecation::enableTrackingDeprecations();
        try {
            $id = $repository->insertGetId([]);
            $repository->translations()->save($id, 'en', ['name' => 'Chair']);
            $repository->links('tags')->sync($id, [1, 2]);
            $repository->with(['tags'])->withLocale('uk', 'en')->findBy(
                Filter::related('tags', ['id IN' => [1, 2]]),
                ['name' => 'ASC'],
            );
            $repository->delete($id);

            self::assertSame([], array_filter(Deprecation::getTriggeredDeprecations()));
        } finally {
            Deprecation::disable();
        }
    }

    private function connection(AbstractPlatform $platform): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        return $connection;
    }

    /** @return Mapping<object> */
    private function productMapping(): Mapping
    {
        $tags = new Mapping('tags', ['id' => Types::INTEGER], static fn (array $row): object => (object) $row);

        return new Mapping(
            'products',
            ['id' => Types::INTEGER],
            static fn (array $row): object => (object) $row,
            translations: new Translations('product_translations', 'product_id', ['name' => Types::STRING], 'en'),
            relations: ['tags' => new BelongsToMany(
                static fn (): Mapping => $tags,
                'product_tag',
                'product_id',
                'tag_id',
                static fn (object $product, array $tags): object => $product,
            )],
        );
    }
}
