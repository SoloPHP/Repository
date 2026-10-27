<?php

declare(strict_types=1);

namespace Solo\Repository;

use InvalidArgumentException;
use Solo\Repository\Internal\Identifier;
use Solo\Repository\Internal\OrderBy;

final readonly class Load
{
    /** @var array<string, 'ASC'|'DESC'> */
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
        if (!Identifier::isPath($name)) {
            throw new InvalidArgumentException('A load name must be a relation name or a dotted relation path.');
        }

        $this->orderBy = OrderBy::normalize($orderBy);
    }
}
