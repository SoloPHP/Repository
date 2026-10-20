<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use InvalidArgumentException;

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
}
