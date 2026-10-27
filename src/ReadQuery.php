<?php

declare(strict_types=1);

namespace Solo\Repository;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Generator;
use InvalidArgumentException;
use LogicException;
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

    private const string ALIAS_PATTERN = '/\A[a-z_][a-z0-9_]*\z/';

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
        private bool $locked = false,
    ) {
        $this->executor = new ReadExecutor($db, $mapping, $visibility, $locale, $locked, $loads);
    }

    /** @return self<T> */
    public function withVisibility(Visibility $visibility): self
    {
        return $this->copy(visibility: $visibility);
    }

    /** @return self<T> */
    public function withLocale(string $locale, ?string $fallback = null): self
    {
        return $this->copy(locale: new Locale($locale, $fallback));
    }

    /**
     * @param list<string|Load> $relations
     * @return self<T>
     */
    public function with(array $relations): self
    {
        return $this->copy(loads: LoadPlan::from($relations));
    }

    /**
     * The same read as a locking one: find(), findOneBy(), findBy(), rows(), exists() and
     * iterateById() run SELECT ... FOR UPDATE and lock the rows they read until the surrounding
     * transaction ends; outside a transaction they throw LogicException. A locking read never
     * answers from the snapshot of a REPEATABLE READ transaction: MySQL reads the latest
     * committed rows, while PostgreSQL, and MariaDB with innodb_snapshot_isolation (its default
     * since 11.6), reject a row changed since the snapshot as a serialization failure. The lock
     * covers the rows of this table (MariaDB, which has no FOR UPDATE OF, also locks the rows
     * the statement joins); relation loads read without locks. Aggregates and queryBuilder()
     * throw LogicException. SQLite has no row locks and runs the plain SELECT.
     *
     * @return self<T>
     */
    public function forUpdate(): self
    {
        return $this->copy(locked: true);
    }

    /** @return T|null */
    public function find(int|string $id): ?object
    {
        return $this->findOneBy([$this->mapping->requirePrimaryKey('find()') => $this->executor->mapper()->id($id)]);
    }

    /**
     * @param array<string, mixed>|Filter $criteria
     * @param array<string, string> $orderBy Fields, or relation paths to fields of BelongsTo targets.
     * @return T|null
     */
    public function findOneBy(array|Filter $criteria, array $orderBy = []): ?object
    {
        return $this->findBy($criteria, $orderBy, 1)[0] ?? null;
    }

    /**
     * @param array<string, mixed>|Filter $criteria
     * @param array<string, string> $orderBy Fields, or relation paths to fields of BelongsTo targets.
     * @return list<T>
     */
    public function findBy(
        array|Filter $criteria = [],
        array $orderBy = [],
        ?int $limit = null,
        int $offset = 0,
    ): array {
        $this->mapping->requireHydrator('Reading entities');
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
        $this->assertUnlocked('Aggregates');

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
     * @param array<string, string> $orderBy Fields, or relation paths to fields of BelongsTo targets.
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
     * A DBAL query over the visible rows that match the criteria, for SQL the read methods cannot
     * express. It selects nothing: add the SELECT and any joins, grouping or order, naming this
     * table by the alias. Add conditions with andWhere(), never where(): where() replaces the
     * whole WHERE clause and silently drops the criteria and the soft-delete condition. Values
     * are bound as named parameters, so bind further values with createNamedParameter(). An IN
     * list is one ArrayParameterType parameter that DBAL expands when it runs the statement, so
     * run the builder itself or pass getParameterTypes() along with getParameters(). Its rows
     * are not converted through the mapping.
     *
     * @param string $alias A lowercase SQL identifier, so that unquoted references match it on every platform.
     * @param array<string, mixed>|Filter $criteria
     */
    public function queryBuilder(string $alias, array|Filter $criteria = []): QueryBuilder
    {
        $this->assertUnlocked('Query builders');
        if ($this->loads->nodes !== []) {
            throw new InvalidArgumentException('Query builders cannot load relations; use with([]) for SQL.');
        }
        if (preg_match(self::ALIAS_PATTERN, $alias) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Query alias "%s" must be a lowercase SQL identifier.', $alias),
            );
        }

        return $this->executor->filtered($alias, $criteria);
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
        $this->mapping->requireHydrator('Reading entities');
        $key = $this->mapping->primaryKey;
        if ($key === null || $this->executor->mapper()->keyType() !== KeyType::Integer) {
            throw new InvalidArgumentException('Keyset iteration requires an integer primary key.');
        }

        $query = $this->executor->selectAll($criteria, [$key => 'ASC'], $batchSize);
        // Later batches bind one more parameter: the key to continue after.
        $this->db->assertParameters(Database::boundCount($query) + 1);

        $loader = new RelationLoader($this->db);
        $loads = $loader->prepare($this->mapping, $this->loads, $this->executor->locale());

        return $this->iterate($query, $key, $criteria, $batchSize, $loader, $loads);
    }

    /**
     * @param array<string, mixed>|Filter $criteria
     * @param list<PreparedLoad> $loads
     * @return Generator<int, T>
     */
    private function iterate(
        QueryBuilder $query,
        string $key,
        array|Filter $criteria,
        int $batchSize,
        RelationLoader $loader,
        array $loads,
    ): Generator {
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
        $this->assertUnlocked('Aggregates');

        return $this->executor->scalar(
            static fn(FieldResolver $fields): string => $function . '(' . $fields->expression($field) . ')',
            $criteria,
        );
    }

    /**
     * The same read with the given settings replaced; a lock, once asked for, is kept.
     *
     * @return self<T>
     */
    private function copy(
        ?Visibility $visibility = null,
        ?Locale $locale = null,
        ?LoadPlan $loads = null,
        bool $locked = false,
    ): self {
        return new self(
            $this->db,
            $this->mapping,
            $visibility ?? $this->visibility,
            $locale ?? $this->locale,
            $loads ?? $this->loads,
            $locked || $this->locked,
        );
    }

    private function assertUnlocked(string $operation): void
    {
        if ($this->locked) {
            throw new LogicException($operation . ' do not lock rows; lock them with a locking findBy() or rows().');
        }
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
