<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
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
    private readonly ?string $alias;
    private readonly QueryBuilder $parameters;

    public function __construct(
        private readonly Connection $connection,
        private readonly Mapping $mapping,
        private readonly QueryBuilder $query,
        ?Locale $locale,
        ?string $alias = null,
        ?QueryBuilder $parameters = null,
    ) {
        if ($alias !== null && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $alias) !== 1) {
            throw new InvalidArgumentException('A field resolver alias must be a plain SQL identifier.');
        }

        $this->locale = $locale ?? ($mapping->translations === null
            ? null
            : new Locale($mapping->translations->defaultLocale));
        $this->alias = $alias ?? ($mapping->translations === null ? null : 'br_base');
        $this->parameters = $parameters ?? $query;
    }

    public function baseAlias(): ?string
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
            return $this->qualified($this->baseAlias(), $field);
        }

        $this->joinTranslations();
        $primary = $this->qualified($this->alias . '_translation', $field);

        return $this->locale?->fallback === null
            ? $primary
            : 'COALESCE(' . $primary . ', ' . $this->qualified($this->alias . '_fallback', $field) . ')';
    }

    public function projection(string $field): string
    {
        $expression = $this->expression($field);

        return array_key_exists($field, $this->mapping->columns)
            ? $expression
            : $expression . ' AS ' . $this->connection->quoteIdentifier($field);
    }

    private function joinTranslations(): void
    {
        if ($this->joined) {
            return;
        }

        $translations = $this->mapping->translations;
        $locale = $this->locale;
        if ($translations === null || $locale === null || $this->alias === null) {
            throw new \LogicException('Translation fields require a translation mapping and locale.');
        }

        $locales = [$this->alias . '_translation' => $locale->locale];
        if ($locale->fallback !== null) {
            $locales[$this->alias . '_fallback'] = $locale->fallback;
        }
        if (count($this->parameters->getParameters()) + count($locales) > PredicateCompiler::MAX_PARAMETERS) {
            throw new InvalidArgumentException('Translation joins exceed the query parameter limit.');
        }

        $parameterIndex = 0;
        foreach ($locales as $alias => $language) {
            do {
                $parameterName = 'locale' . ++$parameterIndex;
            } while (array_key_exists($parameterName, $this->parameters->getParameters()));
            $parameter = $this->parameters->createNamedParameter($language, Types::STRING, ':' . $parameterName);
            $this->query->leftJoin(
                $this->alias,
                $this->connection->quoteIdentifier($translations->table),
                $alias,
                $this->qualified($this->alias, $this->mapping->primaryKey)
                    . ' = ' . $this->qualified($alias, $translations->foreignKey)
                    . ' AND ' . $this->qualified($alias, $translations->localeColumn) . ' = ' . $parameter,
            );
        }

        $this->joined = true;
    }

    private function qualified(?string $alias, string $column): string
    {
        return ($alias === null ? '' : $this->connection->quoteIdentifier($alias) . '.')
            . $this->connection->quoteIdentifier($column);
    }
}
