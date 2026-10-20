<?php

declare(strict_types=1);

namespace Solo\Repository\Relations;

use InvalidArgumentException;

final readonly class BelongsToMany extends Relation
{
    public function __construct(
        callable $repository,
        public string $pivotTable,
        public string $foreignPivotKey,
        public string $relatedPivotKey,
        callable $assign,
    ) {
        self::validateIdentifier($pivotTable);
        self::validateIdentifier($foreignPivotKey);
        self::validateIdentifier($relatedPivotKey);
        if ($foreignPivotKey === $relatedPivotKey) {
            throw new InvalidArgumentException('Pivot foreign and related key columns must be different.');
        }
        parent::__construct($repository, $assign);
    }
}
