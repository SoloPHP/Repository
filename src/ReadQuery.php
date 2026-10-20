<?php

declare(strict_types=1);

namespace Solo\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Solo\Repository\Internal\LoadPlan;
use Solo\Repository\Internal\Locale;
use Solo\Repository\Internal\PredicateCompiler;
use Solo\Repository\Internal\PreparedLoad;
use Solo\Repository\Internal\ReadExecutor;
use Solo\Repository\Internal\Record;
use Solo\Repository\Internal\RelationLoader;
use Solo\Repository\Internal\RowMapper;

/**
 * Independent, reusable read settings for one mapping.
 *
 * @template T of object
 */
final readonly class ReadQuery
{
    private ?Locale $locale;
    private LoadPlan $loads;

    /** @var ReadExecutor<T> */
    private ReadExecutor $executor;

    /**
     * @param Mapping<T> $mapping
     * @param list<string|Load> $relations
     */
    public function __construct(
        private Connection $connection,
        private Mapping $mapping,
        private Visibility $visibility = Visibility::Active,
        ?string $locale = null,
        ?string $fallback = null,
        private array $relations = [],
    ) {
        if ($locale === null && $fallback !== null) {
            throw new InvalidArgumentException('A fallback requires an explicit locale.');
        }

        $this->locale = $locale === null ? null : new Locale($locale, $fallback);
        $this->loads = LoadPlan::from($relations);
        $this->executor = new ReadExecutor($connection, $mapping, $visibility, $this->locale);
    }

    /** @return self<T> */
    public function withVisibility(Visibility $visibility): self
    {
        return new self(
            $this->connection,
            $this->mapping,
            $visibility,
            $this->locale?->locale,
            $this->locale?->fallback,
            $this->relations,
        );
    }

    /** @return self<T> */
    public function withLocale(string $locale, ?string $fallback = null): self
    {
        return new self($this->connection, $this->mapping, $this->visibility, $locale, $fallback, $this->relations);
    }

    /**
     * @param list<string|Load> $relations
     * @return self<T>
     */
    public function with(array $relations): self
    {
        return new self(
            $this->connection,
            $this->mapping,
            $this->visibility,
            $this->locale?->locale,
            $this->locale?->fallback,
            $relations,
        );
    }

    /** @return T|null */
    public function find(int|string $id): ?object
    {
        return $this->findOneBy([$this->mapping->primaryKey => $this->rowMapper()->id($id)]);
    }

    /**
     * @param array<string, mixed>|Filter $criteria
     * @param array<string, string> $orderBy
     * @return T|null
     */
    public function findOneBy(array|Filter $criteria, array $orderBy = []): ?object
    {
        return $this->findBy($criteria, $orderBy, 1)[0] ?? null;
    }

    /**
     * @param array<string, mixed>|Filter $criteria
     * @param array<string, string> $orderBy
     * @return list<T>
     */
    public function findBy(
        array|Filter $criteria = [],
        array $orderBy = [],
        ?int $limit = null,
        int $offset = 0,
    ): array {
        $query = $this->executor->select(
            array_keys($this->mapping->readColumns()),
            $criteria,
            $orderBy,
            $limit,
            $offset,
        );
        $loader = new RelationLoader($this->connection);
        $loads = $loader->prepare($this->mapping, $this->loads, $this->executor->locale());
        if ($limit === 0) {
            return [];
        }

        $records = $this->executor->records($query);
        $loader->load($this->mapping, $records, $loads);

        return array_map(static fn(Record $record): object => $record->entity, $records);
    }

    /** @param array<string, mixed>|Filter $criteria */
    public function exists(array|Filter $criteria = []): bool
    {
        $query = $this->executor->baseQuery($criteria)->select('1')->setMaxResults(1);
        $result = $query->executeQuery();
        try {
            return $result->fetchOne() !== false;
        } finally {
            $result->free();
        }
    }

    /** @param array<string, mixed>|Filter $criteria */
    public function count(array|Filter $criteria = []): int
    {
        $query = $this->executor->baseQuery($criteria)->select('COUNT(*)');
        $result = $query->executeQuery();
        try {
            return (int) $result->fetchOne();
        } finally {
            $result->free();
        }
    }

    /**
     * @param list<string> $columns
     * @param array<string, mixed>|Filter $criteria
     * @param array<string, string> $orderBy
     * @return list<array<string, mixed>>
     */
    public function rows(
        array $columns,
        array|Filter $criteria = [],
        array $orderBy = [],
        ?int $limit = null,
        int $offset = 0,
    ): array {
        if ($this->loads->nodes !== []) {
            throw new InvalidArgumentException('Rows cannot load relations; use with([]) for a partial projection.');
        }
        $query = $this->executor->select($columns, $criteria, $orderBy, $limit, $offset);
        if ($limit === 0) {
            return [];
        }

        $mapper = $this->rowMapper();
        $rows = [];
        foreach ($this->executor->fetchRows($query) as $row) {
            $rows[] = $mapper->convert($row);
        }

        return $rows;
    }

    /**
     * Read ascending integer primary keys in bounded batches, without a snapshot guarantee.
     * Validation runs immediately; SQL starts when the iterable is consumed.
     *
     * @param array<string, mixed>|Filter $criteria
     * @return iterable<T>
     */
    public function iterateById(array|Filter $criteria = [], int $batchSize = 500): iterable
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Batch size must be greater than zero.');
        }

        $key = $this->mapping->primaryKey;
        if (!in_array($this->mapping->columnType($key), [Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)) {
            throw new InvalidArgumentException('Keyset iteration requires an integer primary key.');
        }

        $query = $this->executor->select(
            array_keys($this->mapping->readColumns()),
            $criteria,
            [$key => 'ASC'],
            $batchSize,
        );
        if (count($query->getParameters()) >= PredicateCompiler::MAX_PARAMETERS) {
            throw new InvalidArgumentException('Keyset iteration needs one available parameter for its cursor.');
        }

        $loader = new RelationLoader($this->connection);
        $loads = $loader->prepare($this->mapping, $this->loads, $this->executor->locale());

        return $this->iterate($query, $criteria, $batchSize, $loader, $loads);
    }

    /**
     * @param array<string, mixed>|Filter $criteria
     * @param list<PreparedLoad> $loads
     * @return \Generator<int, T>
     */
    private function iterate(
        QueryBuilder $query,
        array|Filter $criteria,
        int $batchSize,
        RelationLoader $loader,
        array $loads,
    ): \Generator {
        $mapper = $this->rowMapper();
        $key = $this->mapping->primaryKey;
        while (true) {
            $records = $this->executor->records($query);
            $size = count($records);
            if ($size === 0) {
                return;
            }

            // Continue from the database key, independent of models or relation assignments.
            $lastId = $mapper->id($records[$size - 1]->row[$key]);
            $loader->load($this->mapping, $records, $loads);
            foreach ($records as $record) {
                yield $record->entity;
            }
            unset($records, $record);

            if ($size < $batchSize) {
                return;
            }

            $query = $this->executor->select(
                array_keys($this->mapping->readColumns()),
                Filter::all($criteria, [$key . ' >' => $lastId]),
                [$key => 'ASC'],
                $batchSize,
            );
        }
    }

    /** @return RowMapper<T> */
    private function rowMapper(): RowMapper
    {
        return new RowMapper($this->mapping, $this->connection->getDatabasePlatform());
    }
}
