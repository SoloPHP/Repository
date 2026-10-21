<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use LogicException;
use Solo\Repository\Mapping;

/**
 * Resolves logical read fields and adds translation joins once per SQL statement.
 *
 * @internal
 */
final class FieldResolver
{
    private bool $joined = false;
    private readonly ?Locale $locale;
    private readonly QueryBuilder $parameters;

    /**
     * @param Mapping<object> $mapping
     * @param QueryBuilder|null $parameters Statement that binds locale parameters; a subquery binds on its root.
     */
    public function __construct(
        private readonly Database $db,
        private readonly Mapping $mapping,
        private readonly QueryBuilder $query,
        private readonly string $alias,
        ?Locale $locale,
        ?QueryBuilder $parameters = null,
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
        $this->db->assertParameters(count($this->parameters->getParameters()) + count($locales));

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
                $this->db->qualified($this->alias, $this->mapping->primaryKey)
                    . ' = ' . $this->db->qualified($alias, $translations->foreignKey)
                    . ' AND ' . $this->db->qualified($alias, $translations->localeColumn) . ' = ' . $parameter,
            );
        }

        $this->joined = true;
    }
}
