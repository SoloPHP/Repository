<?php

declare(strict_types=1);

namespace Solo\Repository;

use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Solo\Repository\Internal\Identifier;
use Solo\Repository\Internal\Locale;

final readonly class Translations
{
    /** @param array<string, string> $columns */
    public function __construct(
        public string $table,
        public string $foreignKey,
        public array $columns,
        public string $defaultLocale,
        public string $localeColumn = 'locale',
    ) {
        Identifier::assert($table);
        Identifier::assert($foreignKey);
        Identifier::assert($localeColumn);
        new Locale($defaultLocale);

        if ($foreignKey === $localeColumn) {
            throw new InvalidArgumentException('Translation foreign key and locale column must be different.');
        }

        self::validateColumns($columns, $foreignKey, $localeColumn);
    }

    /** @param array<mixed> $columns */
    private static function validateColumns(array $columns, string $foreignKey, string $localeColumn): void
    {
        if ($columns === []) {
            throw new InvalidArgumentException('Translations require at least one translated column.');
        }

        foreach ($columns as $column => $type) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Translation columns must be keyed by column names.');
            }

            Identifier::assert($column);

            if ($column === $foreignKey || $column === $localeColumn) {
                throw new InvalidArgumentException('Translation key and locale columns cannot be translated fields.');
            }

            if (!in_array($type, [Types::STRING, Types::TEXT], true)) {
                throw new InvalidArgumentException('Translated columns must use the DBAL string or text type.');
            }
        }
    }
}
