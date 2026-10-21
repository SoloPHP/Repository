<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use Solo\Repository\Filter;
use Solo\Repository\Internal\Predicate\Condition;
use Solo\Repository\Internal\Predicate\Exists;
use Solo\Repository\Internal\Predicate\Group;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsToMany;

/**
 * Validates every branch, folds constants, and only then renders SQL and bound parameters.
 *
 * @internal
 */
final class PredicateCompiler
{
    private const string CONDITION = '/\A(' . Identifier::PATTERN
        . ')(?:\s+(=|!=|>=|<=|>|<|NOT\s+LIKE|LIKE|NOT\s+IN|IN))?\z/i';

    private int $parameterIndex = 0;
    private int $relationIndex = 0;

    /**
     * @param Mapping<object> $mapping
     * @param FieldResolver|null $fields Read fields with translations and relation predicates;
     *                                   NULL compiles write criteria over unqualified base columns.
     */
    public function __construct(
        private readonly Database $db,
        private readonly Mapping $mapping,
        private readonly QueryBuilder $builder,
        private readonly ?FieldResolver $fields = null,
    ) {
    }

    /** @param array<array-key, mixed>|Filter $criteria */
    public function compile(array|Filter $criteria): string|bool
    {
        $predicate = $this->normalize($criteria, $this->mapping);
        if (is_bool($predicate)) {
            return $predicate;
        }

        // Reject oversized criteria before binding them; translation joins bind their locales while rendering.
        $this->db->assertParameters(count($this->builder->getParameters()) + self::countValues($predicate));
        $sql = $this->render($predicate, $this->fields);
        $this->db->assertParameters(count($this->builder->getParameters()));

        return $sql;
    }

    /**
     * @param array<array-key, mixed>|Filter $criteria
     * @param Mapping<object> $mapping
     */
    private function normalize(array|Filter $criteria, Mapping $mapping): Condition|Group|Exists|bool
    {
        if ($criteria instanceof Filter) {
            return match ($criteria->operator) {
                FilterOperator::Related => $this->normalizeExists($criteria, $mapping),
                FilterOperator::Not => self::negate($this->normalize($criteria->parts[0], $mapping)),
                default => self::fold($criteria->operator, array_map(
                    fn (array|Filter $part): Condition|Group|Exists|bool => $this->normalize($part, $mapping),
                    $criteria->parts,
                )),
            };
        }

        $parts = [];
        foreach ($criteria as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Criteria must use column names as keys.');
            }

            [$field, $operator] = self::parseCondition($key);
            $type = $this->fields === null ? $mapping->columnType($field) : $mapping->fieldType($field);
            self::validateValue($operator, $value);
            $parts[] = $value === [] ? $operator === 'NOT IN' : new Condition($field, $operator, $value, $type);
        }

        return self::fold(FilterOperator::All, $parts);
    }

    /** @param Mapping<object> $owner */
    private function normalizeExists(Filter $filter, Mapping $owner): Exists|bool
    {
        if ($this->fields === null) {
            throw new InvalidArgumentException('Related filters are supported only for read queries.');
        }

        $relation = $owner->relation($filter->relation);
        $target = $relation->resolve($owner);
        SoftDelete::assertSupported($target, $filter->visibility);

        $criteria = $this->normalize($filter->parts[0], $target);
        if ($criteria === false) {
            return false;
        }

        return new Exists($relation, $owner, $target, $criteria === true ? null : $criteria, $filter->visibility);
    }

    /** @param list<Condition|Group|Exists|bool> $parts */
    private static function fold(FilterOperator $operator, array $parts): Condition|Group|Exists|bool
    {
        $identity = $operator === FilterOperator::All;
        $remaining = [];
        foreach ($parts as $part) {
            if (!is_bool($part)) {
                $remaining[] = $part;
            } elseif ($part !== $identity) {
                return $part;
            }
        }

        return match (count($remaining)) {
            0 => $identity,
            1 => $remaining[0],
            default => new Group($operator, $remaining),
        };
    }

    private static function negate(Condition|Group|Exists|bool $part): Group|bool
    {
        return is_bool($part) ? !$part : new Group(FilterOperator::Not, [$part]);
    }

    /** @return array{string, string} */
    private static function parseCondition(string $condition): array
    {
        if (preg_match(self::CONDITION, trim($condition), $matches) !== 1) {
            throw new InvalidArgumentException('Unsupported condition: ' . $condition);
        }

        $operator = isset($matches[2]) ? strtoupper((string) preg_replace('/\s+/', ' ', $matches[2])) : '=';

        return [$matches[1], $operator];
    }

    private static function validateValue(string $operator, mixed $value): void
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

    private static function countValues(Condition|Group|Exists $predicate): int
    {
        return match (true) {
            $predicate instanceof Condition => is_array($predicate->value)
                ? count($predicate->value)
                : ($predicate->value === null ? 0 : 1),
            $predicate instanceof Group => array_sum(array_map(self::countValues(...), $predicate->parts)),
            default => $predicate->criteria === null ? 0 : self::countValues($predicate->criteria),
        };
    }

    private function render(Condition|Group|Exists $predicate, ?FieldResolver $fields): string
    {
        if ($predicate instanceof Exists) {
            assert($fields !== null, 'Relation predicates are normalized only in read mode.');

            return $this->renderExists($predicate, $fields);
        }

        if ($predicate instanceof Group) {
            $parts = [];
            foreach ($predicate->parts as $part) {
                $parts[] = $this->render($part, $fields);
            }

            return match ($predicate->operator) {
                FilterOperator::Not => 'NOT (' . $parts[0] . ')',
                FilterOperator::Any => '(' . implode(' OR ', $parts) . ')',
                default => '(' . implode(' AND ', $parts) . ')',
            };
        }

        $field = $fields?->expression($predicate->field) ?? $this->db->quote($predicate->field);
        if ($predicate->value === null) {
            return $field . ($predicate->operator === '=' ? ' IS NULL' : ' IS NOT NULL');
        }

        if (!is_array($predicate->value)) {
            return $field . ' ' . $predicate->operator . ' ' . $this->bind($predicate->value, $predicate->type);
        }

        $parameters = [];
        foreach ($predicate->value as $element) {
            $parameters[] = $this->bind($element, $predicate->type);
        }

        return $field . ' ' . $predicate->operator . ' (' . implode(', ', $parameters) . ')';
    }

    private function renderExists(Exists $exists, FieldResolver $parent): string
    {
        $number = ++$this->relationIndex;
        // Every subquery alias derives from the root alias, so nested aliases stay short and unique.
        $prefix = ($this->fields ?? $parent)->alias();
        $alias = $prefix . '_related' . $number;
        $target = $exists->target;
        $owner = $exists->owner;
        $relation = $exists->relation;
        $query = $this->db->connection->createQueryBuilder()->select('1')
            ->from($this->db->quote($target->table), $alias);
        $fields = new FieldResolver($this->db, $target, $query, $alias, $parent->locale(), $this->builder);

        if ($relation instanceof BelongsToMany) {
            $pivot = $prefix . '_pivot' . $number;
            $query->innerJoin(
                $alias,
                $this->db->quote($relation->pivotTable),
                $pivot,
                $this->db->qualified($alias, $target->primaryKey)
                    . ' = ' . $this->db->qualified($pivot, $relation->relatedPivotKey),
            );
            $query->where(
                $this->db->qualified($parent->alias(), $owner->primaryKey)
                    . ' = ' . $this->db->qualified($pivot, $relation->foreignPivotKey),
            );
        } else {
            $query->where($relation->keyCondition(
                $this->db->qualified($parent->alias(), $relation->ownerKey($owner)),
                $this->db->qualified($alias, $relation->targetKey($target)),
            ));
        }

        $visibility = SoftDelete::condition($target, $exists->visibility, $fields->expression(...));
        if ($visibility !== null) {
            $query->andWhere($visibility);
        }

        if ($exists->criteria !== null) {
            $query->andWhere($this->render($exists->criteria, $fields));
        }

        return 'EXISTS (' . $query->getSQL() . ')';
    }

    private function bind(mixed $value, string $type): string
    {
        do {
            $name = 'predicate' . ++$this->parameterIndex;
        } while (array_key_exists($name, $this->builder->getParameters()));

        return $this->builder->createNamedParameter($value, $type, ':' . $name);
    }
}
