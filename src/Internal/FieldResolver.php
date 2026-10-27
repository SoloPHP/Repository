<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use LogicException;
use Solo\Repository\Mapping;
use Solo\Repository\Relations\BelongsTo;
use Solo\Repository\Visibility;

/**
 * Resolves logical read fields and adds translation and ordering joins once per SQL statement.
 *
 * @internal
 */
final class FieldResolver
{
    private bool $joined = false;
    private readonly ?Locale $locale;
    private readonly QueryBuilder $parameters;

    /** @var array<string, self> Fields of the BelongsTo targets joined for ordering, by relation path. */
    private array $targets = [];

    /**
     * @param Mapping<object> $mapping
     * @param QueryBuilder|null $parameters Statement that binds locale parameters; a subquery binds on its root.
     * @param LoadPlan|null $loads The relations the read loads; an order join sees the rows its load sees.
     */
    public function __construct(
        private readonly Database $db,
        private readonly Mapping $mapping,
        private readonly QueryBuilder $query,
        private readonly string $alias,
        ?Locale $locale,
        ?QueryBuilder $parameters = null,
        private readonly ?LoadPlan $loads = null,
    ) {
        $this->locale = Locale::effective($locale, $mapping);
        $this->parameters = $parameters ?? $query;
    }

    public function alias(): string
    {
        return $this->alias;
    }

    public function locale(): ?Locale
    {
        return $this->locale;
    }

    public function expression(string $field): string
    {
        $this->mapping->fieldType($field);
        if (array_key_exists($field, $this->mapping->columns)) {
            return $this->db->qualified($this->alias, $field);
        }

        $locale = $this->locale ?? throw new LogicException(sprintf(
            'Translated field "%s" needs a locale: read through withLocale() or give the mapping a default locale.',
            $field,
        ));
        $this->joinTranslations($locale);
        $primary = $this->db->qualified($this->alias . '_translation', $field);

        return $locale->fallback === null
            ? $primary
            : 'COALESCE(' . $primary . ', ' . $this->db->qualified($this->alias . '_fallback', $field) . ')';
    }

    /**
     * A read field, or a field of a BelongsTo target named by a dotted relation path such as
     * "category.name". Each target is joined once per statement over the rows a load of that
     * relation in the same read sees (active ones without such a load), so a row without a
     * visible target orders as one with NULL there.
     */
    public function orderExpression(string $field): string
    {
        $dot = strrpos($field, '.');

        return $dot === false
            ? $this->expression($field)
            : $this->target(substr($field, 0, $dot))->expression(substr($field, $dot + 1));
    }

    /** A read field under its own name; without a locale, a translated field reads as NULL. */
    public function projection(string $field): string
    {
        if ($this->locale === null && isset($this->mapping->translations?->columns[$field])) {
            return 'NULL AS ' . $this->db->quote($field);
        }
        $expression = $this->expression($field);

        return array_key_exists($field, $this->mapping->columns)
            ? $expression
            : $expression . ' AS ' . $this->db->quote($field);
    }

    /**
     * The fields of the BelongsTo target at the end of a relation path, LEFT JOINed on first use.
     * The join sees the target rows its load in the same read sees, so a list that shows a
     * soft-deleted category through a load of every row also orders by that category's name.
     */
    private function target(string $path): self
    {
        if (isset($this->targets[$path])) {
            return $this->targets[$path];
        }

        $dot = strrpos($path, '.');
        $owner = $dot === false ? $this : $this->target(substr($path, 0, $dot));
        $name = $dot === false ? $path : substr($path, $dot + 1);
        $relation = $owner->mapping->relation($name);
        if (!$relation instanceof BelongsTo) {
            throw new InvalidArgumentException(
                sprintf('An order path follows BelongsTo relations only; "%s" is not one.', $name),
            );
        }
        $target = $relation->resolve($owner->mapping);
        $load = $owner->loads?->nodes[$name] ?? null;
        $visibility = $load?->load->visibility ?? Visibility::Active;
        SoftDelete::assertSupported($target, $visibility);
        $alias = $this->alias . '_order' . (count($this->targets) + 1);
        $fields = new self(
            $this->db,
            $target,
            $this->query,
            $alias,
            $this->locale,
            $this->parameters,
            $load?->children,
        );
        $condition = $relation->joinCondition($this->db, $owner->alias, $owner->mapping, $alias, $target);
        $visible = SoftDelete::condition($target, $visibility, $fields->expression(...));
        $this->query->leftJoin(
            $owner->alias,
            $this->db->quote($target->table),
            $alias,
            $visible === null ? $condition : $condition . ' AND ' . $visible,
        );

        return $this->targets[$path] = $fields;
    }

    private function joinTranslations(Locale $locale): void
    {
        if ($this->joined) {
            return;
        }

        $translations = $this->mapping->translations
            ?? throw new LogicException('Translated fields require a translation mapping.');

        $locales = [$this->alias . '_translation' => $locale->locale];
        if ($locale->fallback !== null) {
            $locales[$this->alias . '_fallback'] = $locale->fallback;
        }
        $this->db->assertParameters(Database::boundCount($this->parameters) + count($locales));

        $parameterIndex = 0;
        foreach ($locales as $alias => $language) {
            do {
                $parameterName = 'locale' . ++$parameterIndex;
            } while (array_key_exists($parameterName, $this->parameters->getParameters()));
            $parameter = $this->parameters->createNamedParameter($language, Types::STRING, ':' . $parameterName);
            $this->query->leftJoin(
                $this->alias,
                $this->db->quote($translations->table),
                $alias,
                $this->db->qualified($this->alias, $this->mapping->requirePrimaryKey('Translations'))
                    . ' = ' . $this->db->qualified($alias, $translations->foreignKey)
                    . ' AND ' . $this->db->qualified($alias, $translations->localeColumn) . ' = ' . $parameter,
            );
        }

        $this->joined = true;
    }
}
