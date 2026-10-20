<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Solo\Repository\Mapping;
use UnexpectedValueException;

/**
 * @internal
 * @template T of object
 */
final readonly class RowMapper
{
    /** @param Mapping<T> $mapping */
    public function __construct(
        private Mapping $mapping,
        private AbstractPlatform $platform,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function convert(array $row): array
    {
        foreach ($this->mapping->readColumns() as $column => $type) {
            if (array_key_exists($column, $row)) {
                $row[$column] = Type::getType($type)->convertToPHPValue($row[$column], $this->platform);
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return T
     */
    public function hydrate(array $row): object
    {
        return $this->hydrateConverted($this->convert($row));
    }

    /**
     * @param array<string, mixed> $row Already converted DBAL values.
     * @return T
     */
    public function hydrateConverted(array $row): object
    {
        /** @var mixed $entity */
        $entity = ($this->mapping->hydrate)($row);

        if (!is_object($entity)) {
            throw new UnexpectedValueException('The mapping hydrator must return an object.');
        }

        /** @var T $entity */
        return $entity;
    }

    public function id(int|string $id): int|string
    {
        $type = $this->mapping->columnType($this->mapping->primaryKey);

        if (in_array($type, [Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)) {
            if (is_string($id) && preg_match('/\A(?:0|-?[1-9][0-9]*)\z/', $id) !== 1) {
                throw new InvalidArgumentException('An integer primary key requires a canonical decimal ID.');
            }

            if ($type !== Types::BIGINT && filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new InvalidArgumentException('The primary key ID is outside the PHP integer range.');
            }
        } else {
            $id = (string) $id;

            if ($id === '') {
                throw new InvalidArgumentException('A string primary key requires a non-empty ID.');
            }
        }

        $id = Type::getType($type)->convertToPHPValue($id, $this->platform);

        if (!is_int($id) && !is_string($id)) {
            throw new UnexpectedValueException('The mapped primary key must convert to an integer or string.');
        }

        return $id;
    }
}
