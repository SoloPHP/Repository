<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Fixtures;

use ArrayObject;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;

/** A stub connection for asserting the SQL a platform gets, without a server. */
trait RecordsStatements
{
    /**
     * A connection inside a transaction that records every statement it runs, as SQL, parameters
     * and types, and answers queries in turn with the given rows, then with none.
     *
     * @param list<list<array<string, mixed>>> $answers
     * @return array{Connection, ArrayObject<int, array{string, list<mixed>, list<mixed>}>}
     */
    private function recordingConnection(AbstractPlatform $platform, array $answers = []): array
    {
        $statements = new ArrayObject();
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('createQueryBuilder')->willReturnCallback(
            static fn(): QueryBuilder => new QueryBuilder($connection),
        );
        $connection->method('executeQuery')->willReturnCallback(
            function (string $sql, array $parameters = [], array $types = []) use ($statements, &$answers): Result {
                $statements[] = [$sql, array_values($parameters), array_values($types)];
                $rows = array_shift($answers) ?? [];
                $result = $this->createStub(Result::class);
                $result->method('fetchAllAssociative')->willReturn($rows);
                $result->method('fetchOne')->willReturn($rows === [] ? false : 1);

                return $result;
            },
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $parameters = [], array $types = []) use ($statements): int {
                $statements[] = [$sql, array_values($parameters), array_values($types)];

                return 1;
            },
        );

        return [$connection, $statements];
    }
}
