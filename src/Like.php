<?php

declare(strict_types=1);

namespace Solo\Repository;

/**
 * LIKE patterns from plain text, for the LIKE and NOT LIKE criteria operators. A pattern
 * escapes with "\" on every supported database: "\%", "\_" and "\\" match "%", "_" and "\".
 */
final class Like
{
    private function __construct()
    {
    }

    /** The text with "\", "%" and "_" escaped, so that inside a pattern it matches only itself. */
    public static function escape(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    /** A pattern for values that contain the text. */
    public static function contains(string $text): string
    {
        return '%' . self::escape($text) . '%';
    }

    /** A pattern for values that start with the text. */
    public static function startsWith(string $text): string
    {
        return self::escape($text) . '%';
    }

    /** A pattern for values that end with the text. */
    public static function endsWith(string $text): string
    {
        return '%' . self::escape($text);
    }
}
