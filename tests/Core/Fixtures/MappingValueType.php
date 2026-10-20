<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core\Fixtures;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class MappingValueType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?MappingValue
    {
        return $value === null ? null : new MappingValue($value);
    }
}
