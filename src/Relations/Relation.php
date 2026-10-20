<?php

declare(strict_types=1);

namespace Solo\Repository\Relations;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Solo\Repository\Mapping;
use Solo\Repository\Repository;
use UnexpectedValueException;

abstract readonly class Relation
{
    /** @var Closure(): Repository */
    public Closure $repository;

    /** @var Closure(object, object|array|null): object */
    public Closure $assign;

    /**
     * @param callable(): Repository $repository
     * @param callable(object, object|array|null): object $assign
     */
    protected function __construct(callable $repository, callable $assign)
    {
        $this->repository = Closure::fromCallable($repository);
        $this->assign = Closure::fromCallable($assign);
    }

    /** @internal */
    public function resolve(Connection $connection, Mapping $owner): Repository
    {
        if (
            !$this instanceof BelongsTo
            && !$this instanceof HasOne
            && !$this instanceof HasMany
            && !$this instanceof BelongsToMany
        ) {
            throw new InvalidArgumentException('Unsupported relation definition.');
        }

        /** @var mixed $repository */
        $repository = ($this->repository)();
        if (!$repository instanceof Repository) {
            throw new UnexpectedValueException('A relation repository factory must return a Repository.');
        }
        if ($repository->connection() !== $connection) {
            throw new InvalidArgumentException('Related repositories must share the same Connection instance.');
        }

        $target = $repository->mapping();
        if ($this instanceof BelongsTo) {
            $this->validateKeyTypes($owner->columnType($this->foreignKey), $target->columnType($target->primaryKey));
        } elseif ($this instanceof HasOne || $this instanceof HasMany) {
            $this->validateKeyTypes($target->columnType($this->foreignKey), $owner->columnType($owner->primaryKey));
        }

        return $repository;
    }

    protected static function validateIdentifier(string $identifier): void
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid SQL identifier "%s".', $identifier));
        }
    }

    private function validateKeyTypes(string $foreignType, string $primaryType): void
    {
        $families = [
            [Types::SMALLINT, Types::INTEGER, Types::BIGINT],
            [Types::STRING, Types::ASCII_STRING, Types::GUID],
        ];
        foreach ($families as $family) {
            if (in_array($foreignType, $family, true) && in_array($primaryType, $family, true)) {
                return;
            }
        }

        throw new InvalidArgumentException(
            'A relation foreign key and referenced primary key must have compatible types.',
        );
    }
}
