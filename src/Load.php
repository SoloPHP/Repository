<?php

declare(strict_types=1);

namespace Solo\Repository;

use InvalidArgumentException;

final readonly class Load
{
    /** @var array<string, string> */
    public array $orderBy;

    /**
     * @param array<string, mixed>|Filter $criteria
     * @param array<string, string> $orderBy
     */
    public function __construct(
        public string $name,
        public array|Filter $criteria = [],
        array $orderBy = [],
        public Visibility $visibility = Visibility::Active,
    ) {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/', $name) !== 1) {
            throw new InvalidArgumentException('A load name must be a relation name or a dotted relation path.');
        }

        $this->orderBy = self::validateOrder($orderBy);
    }

    /**
     * @param array<mixed> $orderBy
     * @return array<string, string>
     */
    private static function validateOrder(array $orderBy): array
    {
        foreach ($orderBy as $column => &$direction) {
            if (!is_string($column) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $column) !== 1) {
                throw new InvalidArgumentException('Load order must use plain field names.');
            }
            if (!is_string($direction) || !in_array(strtoupper($direction), ['ASC', 'DESC'], true)) {
                throw new InvalidArgumentException('Load order direction must be ASC or DESC.');
            }
            $direction = strtoupper($direction);
        }
        unset($direction);

        return $orderBy;
    }
}
