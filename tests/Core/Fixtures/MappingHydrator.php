<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core\Fixtures;

use stdClass;

final class MappingHydrator
{
    public static function hydrate(array $row): stdClass
    {
        return (object) $row;
    }
}
