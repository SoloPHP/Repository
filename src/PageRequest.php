<?php

declare(strict_types=1);

namespace Solo\Repository;

use InvalidArgumentException;
use Solo\Repository\Internal\Identifier;
use Solo\Repository\Internal\OrderBy;

/**
 * Which page of a read to fetch: its number, counted from 1, its size, and the order that
 * divides the rows into pages. The order belongs to the request because a page number means
 * nothing without one.
 */
final readonly class PageRequest
{
    /** @var array<string, 'ASC'|'DESC'> */
    public array $orderBy;

    /**
     * @param array<string, string> $orderBy Fields, or relation paths to fields of BelongsTo targets.
     */
    public function __construct(
        public int $page,
        public int $perPage,
        array $orderBy = [],
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('Page numbers start at 1.');
        }
        if ($perPage < 1) {
            throw new InvalidArgumentException('Page size must be greater than zero.');
        }
        if ($page - 1 > intdiv(PHP_INT_MAX, $perPage)) {
            throw new InvalidArgumentException('The page starts past the largest integer offset.');
        }

        $this->orderBy = OrderBy::normalize($orderBy);
    }

    /** The number of rows on the pages before this one. */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * The order ended by the key in the direction of its last field, or ascending when there is
     * none, so rows that tie on every requested field keep their pages from one read to the
     * next. A key of several columns, such as a composite key, ends it column by column, and a
     * column the order already names keeps its place and direction. ReadQuery::page() ends it
     * with the primary key or the page order key of the mapping, and a query of its own SQL can end
     * it with the names of its key.
     *
     * @return array<string, 'ASC'|'DESC'>
     */
    public function orderEndingWith(string $key, string ...$keys): array
    {
        $orderBy = $this->orderBy;
        $direction = $orderBy === [] ? 'ASC' : $orderBy[array_key_last($orderBy)];
        foreach ([$key, ...$keys] as $column) {
            if (!Identifier::isPath($column)) {
                throw new InvalidArgumentException(
                    'An order key must be a field name or a relation path to a field.',
                );
            }
            $orderBy[$column] ??= $direction;
        }

        return $orderBy;
    }
}
