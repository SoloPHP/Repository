<?php

declare(strict_types=1);

namespace Solo\Repository\Relations;

final readonly class BelongsTo extends Relation
{
    public function __construct(callable $repository, public string $foreignKey, callable $assign)
    {
        self::validateIdentifier($foreignKey);
        parent::__construct($repository, $assign);
    }
}
