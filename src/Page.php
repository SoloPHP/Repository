<?php

declare(strict_types=1);

namespace Solo\Repository;

use Closure;
use InvalidArgumentException;

/**
 * One page of a read, the request it was read for, and the number of rows on all its pages.
 *
 * @template-covariant T
 */
final readonly class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public PageRequest $request,
    ) {
        self::assertShape($items, $total, $request);
    }

    /**
     * The page of rows read with the request's offset and size. The rows tell the total
     * when they end before the page does, so $count runs only for a full page, or for an
     * empty one past the end, where the rows say nothing about what lies beyond them. The
     * items must be the rows exactly as the read returned them: a row dropped after the read
     * shortens the page and makes it report a total below the real one.
     *
     * @template U
     * @param list<U> $items
     * @param Closure(): int $count The number of rows on all pages.
     * @return self<U>
     */
    public static function of(array $items, PageRequest $request, Closure $count): self
    {
        $offset = $request->offset();
        $size = count($items);
        $total = $size < $request->perPage && ($size > 0 || $offset === 0) ? $offset + $size : $count();

        return new self($items, $total, $request);
    }

    /**
     * The same page with each item replaced by what the callback returns for it.
     *
     * @template U
     * @param callable(T): U $map
     * @return self<U>
     */
    public function map(callable $map): self
    {
        return new self(array_map($map, $this->items), $this->total, $this->request);
    }

    /** @param array<mixed> $items */
    private static function assertShape(array $items, int $total, PageRequest $request): void
    {
        if (!array_is_list($items)) {
            throw new InvalidArgumentException('Page items must be a list.');
        }
        if (count($items) > $request->perPage) {
            throw new InvalidArgumentException('A page cannot hold more items than its size.');
        }
        if ($total < 0) {
            throw new InvalidArgumentException('A page total cannot be negative.');
        }
    }
}
