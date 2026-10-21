<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use InvalidArgumentException;
use Solo\Repository\Mapping;

/** @internal */
final readonly class Locale
{
    public ?string $fallback;

    public function __construct(public string $locale, ?string $fallback = null)
    {
        if (trim($locale) === '' || ($fallback !== null && trim($fallback) === '')) {
            throw new InvalidArgumentException('Locale and fallback must be non-empty when provided.');
        }

        $this->fallback = $fallback === $locale ? null : $fallback;
    }

    /**
     * The inherited locale, otherwise the default locale of a translated mapping.
     *
     * @param Mapping<object> $mapping
     */
    public static function effective(?self $inherited, Mapping $mapping): ?self
    {
        return $inherited ?? ($mapping->translations === null
            ? null
            : new self($mapping->translations->defaultLocale));
    }
}
