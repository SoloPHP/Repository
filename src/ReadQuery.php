<?php

declare(strict_types=1);

namespace Solo\Repository;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Generator;
use InvalidArgumentException;
use Solo\Repository\Internal\Database;
use Solo\Repository\Internal\FieldResolver;
use Solo\Repository\Internal\KeyType;
use Solo\Repository\Internal\LoadPlan;
use Solo\Repository\Internal\Locale;
use Solo\Repository\Internal\PreparedLoad;
use Solo\Repository\Internal\ReadExecutor;
use Solo\Repository\Internal\Record;
use Solo\Repository\Internal\RelationLoader;
use UnexpectedValueException;

/**
 * Independent, reusable read settings for one mapping.
 *
 * @template-covariant T of object
 */
final readonly class ReadQuery
{
    /** Field types whose sum and average keep their meaning; other types would corrupt the result. */
    private const array NUMERIC_TYPES = [
        Types::SMALLINT,
        Types::INTEGER,
        Types::BIGINT,
        Types::DECIMAL,
        Types::FLOAT,
        Types::SMALLFLOAT,
    ];

    /** @var ReadExecutor<T> */
    private ReadExecutor $executor;

    /**
     * @internal Obtain queries from Repository::read(), withLocale() or with().
     * @param Mapping<T> $mapping
     */
    public function __construct(
        private Database $db,
        private Mapping $mapping,
        private Visibility $visibility,
        private ?Locale $locale,
        private LoadPlan $loads,
    ) {
        $this->executor = new ReadExecutor($db, $mapping, $visibility, $locale);
    }

    /** @return self<T> */
    public function withVisibility(Visibility $visibility): self
    {
        return new self($this->db, $this->mapping, $visibility, $this->locale, $this->loads);
    }

    /** @return self<T> */
    public function withLocale(string $locale, ?string $fallback = null): self
    {
        return new self($this->db, $this->mapping, $this->visibility, new Locale($locale, $fallback), $this->loads);
    }

    /**
     * @param list<string|Load> $relations
     * @return self<T>
     */
    public function with(array $relations): self
    {
        return new self($this->db, $this->mapping, $this->visibility, $this->locale, LoadPlan::from($relations));
    }

    /** @return T|null */
    public function find(int|string $id): ?object
    {
        return $this->findOneBy([$this->mapping->primaryKey => $this->executor->mapper()->id($id)]);
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
        $query = $this->executor->selectAll($criteria, $orderBy, $limit, $offset);
        if ($this->loads->nodes === []) {
            return $limit === 0 ? [] : $this->executor->entities($query);
        }

        $loader = new RelationLoader($this->db);
        $loads = $loader->prepare($this->mapping, $this->loads, $this->executor->locale());
        if ($limit === 0) {
            return [];
        }

        $records = $this->executor->records($query);
        $loader->load($this->executor, $records, $loads);

        return array_map(static fn(Record $record): object => $record->entity, $records);
    }

    /** @param array<string, mixed>|Filter $criteria */
    public function exists(array|Filter $criteria = []): bool
    {
        return $this->executor->scalar(static fn(): string => '1', $criteria, 1) !== false;
    }

    /** @param array<string, mixed>|Filter $criteria */
    public function count(array|Filter $criteria = []): int
    {
        return (int) self::number($this->executor->scalar(static fn(): string => 'COUNT(*)', $criteria), 'COUNT(*)');
    }

    /**
     * Sum of a numeric field over the matching rows, converted by the field type; zero when nothing matches.
     *
     * @param array<string, mixed>|Filter $criteria
     */
    public function sum(string $field, array|Filter $criteria = []): mixed
    {
        $this->assertNumeric($field);

        return $this->convert($field, $this->aggregate('SUM', $field, $criteria) ?? 0);
    }

    /**
     * Smallest value of a field over the matching rows, converted by the field type; NULL when nothing matches.
     *
     * @param array<string, mixed>|Filter $criteria
     */
    public function min(string $field, array|Filter $criteria = []): mixed
    {
        return $this->convert($field, $this->aggregate('MIN', $field, $criteria));
    }

    /**
     * Largest value of a field over the matching rows, converted by the field type; NULL when nothing matches.
     *
     * @param array<string, mixed>|Filter $criteria
     */
    public function max(string $field, array|Filter $criteria = []): mixed
    {
        return $this->convert($field, $this->aggregate('MAX', $field, $criteria));
    }

    /**
     * Average of a numeric field over the matching rows; NULL when nothing matches.
     *
     * @param array<string, mixed>|Filter $criteria
     */
    public function avg(string $field, array|Filter $criteria = []): ?float
    {
        $this->assertNumeric($field);
        $average = $this->aggregate('AVG', $field, $criteria);

        return $average === null ? null : (float) self::number($average, 'AVG()');
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

        return $this->executor->convertedRows($query);
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
        if ($this->executor->mapper()->keyType() !== KeyType::Integer) {
            throw new InvalidArgumentException('Keyset iteration requires an integer primary key.');
        }

        $key = $this->mapping->primaryKey;
        $query = $this->executor->selectAll($criteria, [$key => 'ASC'], $batchSize);
        // Later batches bind one more parameter: the key to continue after.
        $this->db->assertParameters(count($query->getParameters()) + 1);

        $loader = new RelationLoader($this->db);
        $loads = $loader->prepare($this->mapping, $this->loads, $this->executor->locale());

        return $this->iterate($query, $criteria, $batchSize, $loader, $loads);
    }

    /**
     * @param array<string, mixed>|Filter $criteria
     * @param list<PreparedLoad> $loads
     * @return Generator<int, T>
     */
    private function iterate(
        QueryBuilder $query,
        array|Filter $criteria,
        int $batchSize,
        RelationLoader $loader,
        array $loads,
    ): Generator {
        $key = $this->mapping->primaryKey;
        while (true) {
            $records = $this->executor->records($query);
            $size = count($records);
            if ($size === 0) {
                return;
            }

            // Continue from the database key, independent of models or relation assignments.
            $lastId = $this->executor->mapper()->rowId($records[$size - 1]->row[$key]);
            $loader->load($this->executor, $records, $loads);
            foreach ($records as $record) {
                yield $record->entity;
            }
            unset($records, $record);

            if ($size < $batchSize) {
                return;
            }

            $query = $this->executor->selectAll(
                Filter::all($criteria, [$key . ' >' => $lastId]),
                [$key => 'ASC'],
                $batchSize,
            );
        }
    }

    /**
     * @param 'SUM'|'MIN'|'MAX'|'AVG' $function
     * @param array<string, mixed>|Filter $criteria
     */
    private function aggregate(string $function, string $field, array|Filter $criteria): mixed
    {
        return $this->executor->scalar(
            static fn(FieldResolver $fields): string => $function . '(' . $fields->expression($field) . ')',
            $criteria,
        );
    }

    private function assertNumeric(string $field): void
    {
        if (!in_array($this->mapping->fieldType($field), self::NUMERIC_TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf('Sums and averages require a numeric field; "%s" is not.', $field),
            );
        }
    }

    private function convert(string $field, mixed $value): mixed
    {
        return $this->executor->mapper()->convert([$field => $value])[$field];
    }

    /** @return int|float|numeric-string */
    private static function number(mixed $value, string $expression): int|float|string
    {
        if (!is_numeric($value)) {
            throw new UnexpectedValueException($expression . ' did not return a number.');
        }

        return $value;
    }
}
