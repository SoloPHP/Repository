<?php

declare(strict_types=1);

namespace Solo\Repository;

use Closure;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Solo\Repository\Relations\BelongsTo;
use Solo\Repository\Relations\Relation;

/**
 * @template T of object
 */
final readonly class Mapping
{
    /** @var Closure(array<string, mixed>): T */
    public Closure $hydrate;

    /**
     * @param array<string, string> $columns
     * @param callable(array<string, mixed>): T $hydrate
     * @param array<string, Relation> $relations
     */
    public function __construct(
        public string $table,
        public array $columns,
        callable $hydrate,
        public string $primaryKey = 'id',
        public bool $generatedId = true,
        public ?string $softDeleteColumn = null,
        public ?Translations $translations = null,
        public array $relations = [],
    ) {
        self::validateIdentifier($table);
        self::validateIdentifier($primaryKey);
        self::validateColumns($columns);
        $this->validateRelations($relations);

        if ($translations !== null && array_intersect_key($columns, $translations->columns) !== []) {
            throw new InvalidArgumentException('Translated columns must not overlap with base columns.');
        }

        $primaryType = $this->columnType($primaryKey);
        $integerTypes = [Types::SMALLINT, Types::INTEGER, Types::BIGINT];
        $primaryTypes = [...$integerTypes, Types::STRING, Types::ASCII_STRING, Types::GUID];

        if (!in_array($primaryType, $primaryTypes, true)) {
            throw new InvalidArgumentException(
                'The primary key must use an integer, string, ASCII string or GUID type.',
            );
        }

        if ($generatedId && !in_array($primaryType, $integerTypes, true)) {
            throw new InvalidArgumentException('Generated IDs require a smallint, integer or bigint primary key.');
        }

        if ($softDeleteColumn !== null) {
            self::validateIdentifier($softDeleteColumn);
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
        if (array_key_exists($field, $this->columns)) {
            return $this->columns[$field];
        }

        if ($this->translations !== null && array_key_exists($field, $this->translations->columns)) {
            return $this->translations->columns[$field];
        }

        throw new InvalidArgumentException(sprintf('Unknown mapped field "%s".', $field));
    }

    /** @return array<string, string> */
    public function readColumns(): array
    {
        return $this->columns + ($this->translations->columns ?? []);
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
            self::validateIdentifier($name);
            if ($relation instanceof BelongsTo) {
                $this->columnType($relation->foreignKey);
            }
        }
    }

    /** @param array<mixed> $columns */
    private static function validateColumns(array $columns): void
    {
        foreach ($columns as $column => $type) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Mapping columns must be keyed by column names.');
            }

            self::validateIdentifier($column);

            if (!is_string($type) || !Type::hasType($type)) {
                throw new InvalidArgumentException(
                    sprintf('Column "%s" must use a registered DBAL type name.', $column),
                );
            }
        }
    }

    private static function validateIdentifier(string $identifier): void
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid SQL identifier "%s".', $identifier));
        }
    }
}
