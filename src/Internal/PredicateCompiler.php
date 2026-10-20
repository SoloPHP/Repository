<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use Solo\Repository\Filter;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsTo;
use Solo\Repository\Relations\BelongsToMany;
use Solo\Repository\Relations\HasMany;
use Solo\Repository\Relations\HasOne;
use Solo\Repository\Visibility;
use WeakMap;

/** @internal */
final class PredicateCompiler
{
    public const int MAX_PARAMETERS = 900;

    private int $parameterIndex = 0;
    private int $relationIndex = 0;

    /** @var WeakMap<Filter, Mapping> */
    private WeakMap $relatedMappings;

    /** @var WeakMap<Filter, array{query: QueryBuilder, fields: FieldResolver, mapping: Mapping}> */
    private WeakMap $relatedQueries;

    public function __construct(
        private readonly Connection $connection,
        private readonly Mapping $mapping,
        private readonly QueryBuilder $builder,
        private readonly ?string $alias = null,
        private readonly ?FieldResolver $fields = null,
    ) {
        $this->relatedMappings = new WeakMap();
        $this->relatedQueries = new WeakMap();
        if ($alias !== null && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $alias) !== 1) {
            throw new InvalidArgumentException('A predicate alias must be a plain SQL identifier.');
        }
    }

    /** @param array<array-key, mixed>|Filter $criteria */
    public function compile(array|Filter $criteria): string|bool
    {
        // Validate every branch before folding constants or touching builder parameters.
        $normalized = $this->normalize($criteria, $this->mapping);
        $this->prepareFields($normalized, $this->mapping, $this->fields);
        $parameterCount = count($this->builder->getParameters()) + $this->countParameters($normalized);

        if ($parameterCount > self::MAX_PARAMETERS) {
            throw new InvalidArgumentException(
                'The query exceeds the parameter limit of ' . self::MAX_PARAMETERS . '.',
            );
        }

        return is_bool($normalized) ? $normalized : $this->render($normalized, $this->mapping, $this->fields);
    }

    /**
     * @param array<array-key, mixed>|Filter $criteria
     * @return array<string, mixed>|Filter|bool
     */
    private function normalize(array|Filter $criteria, Mapping $mapping): array|Filter|bool
    {
        if ($criteria instanceof Filter) {
            if ($criteria->operator === 'RELATED') {
                return $this->normalizeRelated($criteria, $mapping);
            }

            $parts = [];
            foreach ($criteria->parts as $part) {
                $parts[] = $this->normalize($part, $mapping);
            }

            if ($criteria->operator === 'NOT') {
                return is_bool($parts[0]) ? !$parts[0] : Filter::not($parts[0]);
            }

            return $this->fold($criteria->operator, $parts);
        }

        $parts = [];
        foreach ($criteria as $condition => $value) {
            if (!is_string($condition)) {
                throw new InvalidArgumentException('Criteria must use column names as keys.');
            }

            [$column, $operator] = $this->parseCondition($condition);
            $this->columnType($mapping, $column);
            $this->validateValue($operator, $value);

            if (is_array($value) && $value === []) {
                $parts[] = $operator === 'NOT IN';
            } else {
                $parts[] = [$column . ' ' . $operator => $value];
            }
        }

        return $this->fold('AND', $parts);
    }

    private function normalizeRelated(Filter $criteria, Mapping $mapping): Filter|bool
    {
        if ($this->fields === null) {
            throw new InvalidArgumentException('Related filters are supported only for read queries.');
        }

        $relation = $mapping->relation($criteria->relation);
        if (
            !$relation instanceof BelongsTo
            && !$relation instanceof BelongsToMany
            && !$relation instanceof HasOne
            && !$relation instanceof HasMany
        ) {
            throw new InvalidArgumentException('Unsupported related filter definition.');
        }

        $target = $relation->resolve($this->connection, $mapping)->mapping();
        if ($criteria->visibility === Visibility::Deleted && $target->softDeleteColumn === null) {
            throw new InvalidArgumentException('Deleted relation visibility requires a soft-delete column.');
        }

        $child = $this->normalize($criteria->parts[0], $target);
        if ($child === false) {
            return false;
        }

        $normalized = Filter::related($criteria->relation, $child === true ? [] : $child, $criteria->visibility);
        $this->relatedMappings[$normalized] = $target;

        return $normalized;
    }

    /**
     * @param 'AND'|'OR' $operator
     * @param list<array<string, mixed>|Filter|bool> $parts
     * @return array<string, mixed>|Filter|bool
     */
    private function fold(string $operator, array $parts): array|Filter|bool
    {
        $identity = $operator === 'AND';
        $remaining = [];

        foreach ($parts as $part) {
            if (is_bool($part)) {
                if ($part !== $identity) {
                    return $part;
                }
            } else {
                $remaining[] = $part;
            }
        }

        if ($remaining === []) {
            return $identity;
        }

        if (count($remaining) === 1) {
            return $remaining[0];
        }

        return $operator === 'AND' ? Filter::all(...$remaining) : Filter::any(...$remaining);
    }

    /** @return array{string, string} */
    private function parseCondition(string $condition): array
    {
        $matched = preg_match(
            '/\A([A-Za-z_][A-Za-z0-9_]*)(?:\s+(=|!=|>=|<=|>|<|NOT\s+LIKE|LIKE|NOT\s+IN|IN))?\z/i',
            trim($condition),
            $matches,
        );

        if ($matched !== 1) {
            throw new InvalidArgumentException('Unsupported condition: ' . $condition);
        }

        $operator = isset($matches[2]) ? strtoupper((string) preg_replace('/\s+/', ' ', $matches[2])) : '=';

        return [$matches[1], $operator];
    }

    private function validateValue(string $operator, mixed $value): void
    {
        if ($operator === 'IN' || $operator === 'NOT IN') {
            if (!is_array($value)) {
                throw new InvalidArgumentException('IN and NOT IN require an array of values.');
            }

            foreach ($value as $element) {
                if ($element === null || is_array($element)) {
                    throw new InvalidArgumentException('IN values cannot contain NULL or nested arrays.');
                }
            }

            return;
        }

        if (is_array($value)) {
            throw new InvalidArgumentException('Array values require an explicit IN or NOT IN operator.');
        }

        if ($value === null && $operator !== '=' && $operator !== '!=') {
            throw new InvalidArgumentException('NULL is only supported with = or !=.');
        }
    }

    /** @param array<string, mixed>|Filter|bool $criteria */
    private function countParameters(array|Filter|bool $criteria): int
    {
        if (is_bool($criteria)) {
            return 0;
        }

        $count = 0;
        if ($criteria instanceof Filter) {
            foreach ($criteria->parts as $part) {
                $count += $this->countParameters($part);
            }
        } else {
            foreach ($criteria as $value) {
                $count += is_array($value) ? count($value) : ($value === null ? 0 : 1);
            }
        }

        return $count;
    }

    /** @param array<string, mixed>|Filter $criteria */
    private function render(array|Filter $criteria, Mapping $mapping, ?FieldResolver $fields): string
    {
        if ($criteria instanceof Filter) {
            if ($criteria->operator === 'RELATED') {
                $context = $this->relatedQueries[$criteria];
                if ($criteria->parts[0] !== []) {
                    $context['query']->andWhere(
                        $this->render($criteria->parts[0], $context['mapping'], $context['fields']),
                    );
                }

                return 'EXISTS (' . $context['query']->getSQL() . ')';
            }

            if ($criteria->operator === 'NOT') {
                return 'NOT (' . $this->render($criteria->parts[0], $mapping, $fields) . ')';
            }

            $parts = [];
            foreach ($criteria->parts as $part) {
                $parts[] = $this->render($part, $mapping, $fields);
            }

            return '(' . implode(' ' . $criteria->operator . ' ', $parts) . ')';
        }

        $parts = [];
        foreach ($criteria as $condition => $value) {
            [$column, $operator] = $this->parseCondition($condition);
            $field = $fields?->expression($column) ?? $this->connection->quoteIdentifier($column);
            if ($fields === null && $this->alias !== null) {
                $field = $this->connection->quoteIdentifier($this->alias) . '.' . $field;
            }

            if ($value === null) {
                $parts[] = $field . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL');
                continue;
            }

            $type = $this->columnType($mapping, $column);
            if (is_array($value)) {
                $parameters = [];
                foreach ($value as $element) {
                    $parameters[] = $this->bind($element, $type);
                }

                $parts[] = $field . ' ' . $operator . ' (' . implode(', ', $parameters) . ')';
            } else {
                $parts[] = $field . ' ' . $operator . ' ' . $this->bind($value, $type);
            }
        }

        return count($parts) === 1 ? $parts[0] : '(' . implode(' AND ', $parts) . ')';
    }

    /** @param array<string, mixed>|Filter|bool $criteria */
    private function prepareFields(array|Filter|bool $criteria, Mapping $mapping, ?FieldResolver $fields): void
    {
        if ($fields === null || is_bool($criteria)) {
            return;
        }

        // Resolve surviving fields before counting parameters: locale joins also bind values.
        if ($criteria instanceof Filter) {
            if ($criteria->operator === 'RELATED') {
                $context = $this->prepareRelated($criteria, $mapping, $fields);
                $this->relatedQueries[$criteria] = $context;
                $this->prepareFields($criteria->parts[0], $context['mapping'], $context['fields']);

                return;
            }

            foreach ($criteria->parts as $part) {
                $this->prepareFields($part, $mapping, $fields);
            }
        } else {
            foreach ($criteria as $condition => $value) {
                [$column] = $this->parseCondition($condition);
                $fields->expression($column);
            }
        }
    }

    /** @return array{query: QueryBuilder, fields: FieldResolver, mapping: Mapping} */
    private function prepareRelated(Filter $criteria, Mapping $mapping, FieldResolver $parentFields): array
    {
        $target = $this->relatedMappings[$criteria];
        $relation = $mapping->relation($criteria->relation);
        $number = ++$this->relationIndex;
        $prefix = $this->fields?->baseAlias() ?? $this->alias ?? 'br_base';
        $alias = $prefix . '_related' . $number;
        $parentAlias = $parentFields->baseAlias() ?? $mapping->table;
        $query = $this->connection->createQueryBuilder()->select('1')
            ->from($this->connection->quoteIdentifier($target->table), $alias);
        $fields = new FieldResolver(
            $this->connection,
            $target,
            $query,
            $parentFields->locale(),
            alias: $alias,
            parameters: $this->builder,
        );

        if ($relation instanceof BelongsToMany) {
            $pivotAlias = $prefix . '_pivot' . $number;
            $query->innerJoin(
                $alias,
                $this->connection->quoteIdentifier($relation->pivotTable),
                $pivotAlias,
                $this->qualified($alias, $target->primaryKey)
                    . ' = ' . $this->qualified($pivotAlias, $relation->relatedPivotKey),
            );
            $query->where(
                $this->qualified($parentAlias, $mapping->primaryKey)
                    . ' = ' . $this->qualified($pivotAlias, $relation->foreignPivotKey),
            );
        } elseif ($relation instanceof BelongsTo) {
            $query->where(
                $this->qualified($alias, $target->primaryKey)
                    . ' = ' . $this->qualified($parentAlias, $relation->foreignKey),
            );
        } elseif ($relation instanceof HasOne || $relation instanceof HasMany) {
            $query->where(
                $this->qualified($parentAlias, $mapping->primaryKey)
                    . ' = ' . $this->qualified($alias, $relation->foreignKey),
            );
        }

        if ($target->softDeleteColumn !== null && $criteria->visibility !== Visibility::All) {
            $query->andWhere(
                $fields->expression($target->softDeleteColumn)
                    . ($criteria->visibility === Visibility::Deleted ? ' IS NOT NULL' : ' IS NULL'),
            );
        }

        return ['query' => $query, 'fields' => $fields, 'mapping' => $target];
    }

    private function qualified(string $alias, string $column): string
    {
        return $this->connection->quoteIdentifier($alias) . '.' . $this->connection->quoteIdentifier($column);
    }

    private function columnType(Mapping $mapping, string $column): string
    {
        return $this->fields === null
            ? $mapping->columnType($column)
            : $mapping->fieldType($column);
    }

    private function bind(mixed $value, string $type): string
    {
        do {
            $name = 'predicate' . ++$this->parameterIndex;
        } while (array_key_exists($name, $this->builder->getParameters()));

        return $this->builder->createNamedParameter($value, $type, ':' . $name);
    }
}
