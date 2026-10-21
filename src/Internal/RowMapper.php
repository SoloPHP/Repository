<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use LogicException;
use Solo\Repository\Mapping;
use UnexpectedValueException;

/**
 * @internal
 * @template T of object
 */
final readonly class RowMapper
{
    /** @var array<string, Type> */
    private array $types;
    private ?Type $idType;
    private ?KeyType $keyType;
    private bool $bigintKey;

    /** @param Mapping<T> $mapping */
    public function __construct(private Mapping $mapping, private Database $db)
    {
        $types = [];
        foreach ($mapping->readColumns() as $column => $type) {
            $types[$column] = Type::getType($type);
        }
        $this->types = $types;

        $type = $mapping->primaryKey === null ? null : $mapping->columnType($mapping->primaryKey);
        $this->idType = $type === null ? null : Type::getType($type);
        $this->keyType = $type === null
            ? null
            : KeyType::of($type) ?? throw new UnexpectedValueException('Unsupported primary key type.');
        $this->bigintKey = $type === Types::BIGINT;
    }

    /** Prefixed so that numeric strings never collapse into integer array keys. */
    public static function key(int|string $id): string
    {
        return 'key:' . $id;
    }

    /** A converted key value; integer and string key types convert to nothing else. */
    public static function keyValue(mixed $value): int|string
    {
        if (!is_int($value) && !is_string($value)) {
            throw new UnexpectedValueException('Keys must be integers or strings.');
        }

        return $value;
    }

    /** NULL for a mapping without a primary key. */
    public function keyType(): ?KeyType
    {
        return $this->keyType;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function convert(array $row): array
    {
        $platform = $this->db->platform();
        foreach ($row as $column => $value) {
            if (isset($this->types[$column])) {
                $row[$column] = $this->types[$column]->convertToPHPValue($value, $platform);
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row Already converted DBAL values.
     * @return T
     */
    public function hydrate(array $row): object
    {
        /** @var mixed $entity */
        $entity = ($this->mapping->hydrate)($row);

        if (!is_object($entity)) {
            throw new UnexpectedValueException('The mapping hydrator must return an object.');
        }

        /** @var T $entity */
        return $entity;
    }

    /** Normalizes a primary key value read from the database. */
    public function rowId(mixed $value): int|string
    {
        return $this->id(self::keyValue($value));
    }

    /**
     * Distinct normalized IDs keyed by key().
     *
     * @param array<mixed> $ids
     * @return array<string, int|string>
     */
    public function ids(array $ids): array
    {
        if (!array_is_list($ids)) {
            throw new InvalidArgumentException('IDs must be a list.');
        }
        $normalized = [];
        foreach ($ids as $id) {
            if (!is_int($id) && !is_string($id)) {
                throw new InvalidArgumentException('IDs must be integers or strings.');
            }
            $id = $this->id($id);
            $normalized[self::key($id)] = $id;
        }

        return $normalized;
    }

    public function id(int|string $id): int|string
    {
        $idType = $this->idType ?? throw new LogicException(
            sprintf('Mapping "%s" has no primary key to address rows by.', $this->mapping->table),
        );
        if ($this->keyType === KeyType::Integer) {
            if (is_string($id) && !FieldValue::isCanonicalInteger($id)) {
                throw new InvalidArgumentException('An integer primary key requires a canonical decimal ID.');
            }

            if (!$this->bigintKey && filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new InvalidArgumentException('The primary key ID is outside the PHP integer range.');
            }
        } else {
            $id = (string) $id;

            if ($id === '') {
                throw new InvalidArgumentException('A string primary key requires a non-empty ID.');
            }
        }

        $id = $idType->convertToPHPValue($id, $this->db->platform());

        if (!is_int($id) && !is_string($id)) {
            throw new UnexpectedValueException('The mapped primary key must convert to an integer or string.');
        }

        return $id;
    }
}
