<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core\Fixtures;

final class Note
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly int $done,
    ) {
    }
}
