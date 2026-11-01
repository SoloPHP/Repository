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
     * The order ended by $key in the direction of its last field, or ascending when there is
     * none, so rows that tie on every requested field keep their pages from one read to the
     * next; an order that already names the key comes back as it is. ReadQuery::page() ends it
     * with the primary key, and a query of its own SQL can end it with the name of its key.
     *
     * @return array<string, 'ASC'|'DESC'>
     */
    public function orderEndingWith(string $key): array
    {
        if (!Identifier::isPath($key)) {
            throw new InvalidArgumentException('An order key must be a field name or a relation path to a field.');
        }
        $orderBy = $this->orderBy;
        $orderBy[$key] ??= $orderBy === [] ? 'ASC' : $orderBy[array_key_last($orderBy)];

        return $orderBy;
    }
}
