<?php

declare(strict_types=1);

namespace Solo\Repository;

use Closure;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use LogicException;
use Solo\Repository\Internal\Identifier;
use Solo\Repository\Internal\KeyType;
use Solo\Repository\Relations\BelongsTo;
use Solo\Repository\Relations\Relation;

/**
 * @template-covariant T of object
 */
final readonly class Mapping
{
    /** @var Closure(array<string, mixed>): T */
    public Closure $hydrate;

    /** Whether the database generates the primary key; always false without one. */
    public bool $generatedId;

    /** @var array<string, string> */
    private array $readColumns;

    /**
     * @param array<string, string> $columns
     * @param callable(array<string, mixed>): T $hydrate
     * @param string|null $primaryKey NULL for a table without a single-column key, such as one with a
     *                                composite key; its rows are then read and changed by criteria only.
     * @param array<string, Relation> $relations
     */
    public function __construct(
        public string $table,
        public array $columns,
        callable $hydrate,
        public ?string $primaryKey = 'id',
        bool $generatedId = true,
        public ?string $softDeleteColumn = null,
        public ?Translations $translations = null,
        public array $relations = [],
    ) {
        Identifier::assert($table);
        if ($primaryKey !== null) {
            Identifier::assert($primaryKey);
        }
        self::validateColumns($columns);
        $this->validateRelations($relations);

        if ($translations !== null && array_intersect_key($columns, $translations->columns) !== []) {
            throw new InvalidArgumentException('Translated columns must not overlap with base columns.');
        }

        if ($primaryKey !== null) {
            $this->validatePrimaryKey($primaryKey, $generatedId);
        } elseif ($translations !== null) {
            throw new InvalidArgumentException('Translations require a primary key.');
        }
        $this->generatedId = $primaryKey !== null && $generatedId;

        if ($softDeleteColumn !== null) {
            Identifier::assert($softDeleteColumn);
            $softDeleteType = $this->columnType($softDeleteColumn);
            $dateTimeTypes = [
                Types::DATETIME_MUTABLE,
                Types::DATETIME_IMMUTABLE,
                Types::DATETIMETZ_MUTABLE,
                Types::DATETIMETZ_IMMUTABLE,
            ];

            if (!in_array($softDeleteType, $dateTimeTypes, true)) {
                throw new InvalidArgumentException('The soft-delete column must use a datetime or datetimetz type.');
            }
        }

        $this->hydrate = Closure::fromCallable($hydrate);
        $this->readColumns = $columns + ($translations->columns ?? []);
    }

    /** @internal The primary key that an operation addresses rows by. */
    public function requirePrimaryKey(string $operation): string
    {
        return $this->primaryKey ?? throw new LogicException(
            sprintf('%s requires a primary key; mapping "%s" has none.', $operation, $this->table),
        );
    }

    public function columnType(string $column): string
    {
        if (!array_key_exists($column, $this->columns)) {
            throw new InvalidArgumentException(sprintf('Unknown mapped column "%s".', $column));
        }

        return $this->columns[$column];
    }

    public function fieldType(string $field): string
    {
        if (!array_key_exists($field, $this->readColumns)) {
            throw new InvalidArgumentException(sprintf('Unknown mapped field "%s".', $field));
        }

        return $this->readColumns[$field];
    }

    /** @return array<string, string> */
    public function readColumns(): array
    {
        return $this->readColumns;
    }

    public function relation(string $name): Relation
    {
        if (!array_key_exists($name, $this->relations)) {
            throw new InvalidArgumentException(sprintf('Unknown mapped relation "%s".', $name));
        }

        return $this->relations[$name];
    }

    /** @param array<mixed> $relations */
    private function validateRelations(array $relations): void
    {
        foreach ($relations as $name => $relation) {
            if (!is_string($name) || !$relation instanceof Relation) {
                throw new InvalidArgumentException(
                    'Mapping relations must map relation names to Relation definitions.',
                );
            }
            Identifier::assert($name);
            if ($this->primaryKey === null && !$relation instanceof BelongsTo) {
                throw new InvalidArgumentException(
                    sprintf('Relation "%s" requires a primary key on its owner.', $name),
                );
            }
            $this->columnType($relation->ownerKey($this));
        }
    }

    private function validatePrimaryKey(string $primaryKey, bool $generatedId): void
    {
        $keyType = KeyType::of($this->columnType($primaryKey));
        if ($keyType === null) {
            throw new InvalidArgumentException(
                'The primary key must use an integer, string, ASCII string or GUID type.',
            );
        }

        if ($generatedId && $keyType !== KeyType::Integer) {
            throw new InvalidArgumentException('Generated IDs require a smallint, integer or bigint primary key.');
        }
    }

    /** @param array<mixed> $columns */
    private static function validateColumns(array $columns): void
    {
        foreach ($columns as $column => $type) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Mapping columns must be keyed by column names.');
            }

            Identifier::assert($column);

            if (!is_string($type) || !Type::hasType($type)) {
                throw new InvalidArgumentException(
                    sprintf('Column "%s" must use a registered DBAL type name.', $column),
                );
            }
        }
    }
}
