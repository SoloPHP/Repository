<?php

declare(strict_types=1);

namespace Solo\Repository\Relations;

use Closure;
use InvalidArgumentException;
use Solo\Repository\Internal\KeyType;
use Solo\Repository\Internal\OrderBy;
use Solo\Repository\Mapping;
use UnexpectedValueException;
use WeakMap;

/**
 * Common base of the four relation definitions; it is not an extension point.
 */
abstract readonly class Relation
{
    /** Lazy factory of the target Mapping, called once per owner mapping on first use. */
    public Closure $target;

    /**
     * Receives the owner and the loaded value and returns the owner to keep. NULL for a relation
     * that is only filtered by or linked, which cannot be loaded.
     */
    public ?Closure $assign;

    /** @var array<string, 'ASC'|'DESC'> Default collection order; a Load with its own order replaces it. */
    public array $orderBy;

    /** @var WeakMap<Mapping<object>, Mapping<object>> */
    private WeakMap $resolved;

    /** @param array<mixed> $orderBy */
    protected function __construct(callable $target, ?callable $assign, array $orderBy = [])
    {
        $this->target = Closure::fromCallable($target);
        $this->assign = $assign === null ? null : Closure::fromCallable($assign);
        $this->orderBy = OrderBy::normalize($orderBy);
        $this->resolved = new WeakMap();
    }

    /**
     * The target Mapping with the relation keys checked against both mappings. The factory runs
     * once per owner mapping; later calls reuse its result.
     *
     * @internal
     * @param Mapping<object> $owner
     * @return Mapping<object>
     */
    public function resolve(Mapping $owner): Mapping
    {
        // offsetSet() changes the map, not the readonly property that holds it.
        if (!isset($this->resolved[$owner])) {
            $this->resolved->offsetSet($owner, $this->resolveTarget($owner));
        }

        return $this->resolved[$owner];
    }

    /**
     * @param Mapping<object> $owner
     * @return Mapping<object>
     */
    private function resolveTarget(Mapping $owner): Mapping
    {
        if (
            !$this instanceof BelongsTo
            && !$this instanceof HasOne
            && !$this instanceof HasMany
            && !$this instanceof BelongsToMany
        ) {
            throw new InvalidArgumentException('Unsupported relation definition.');
        }

        /** @var mixed $target */
        $target = ($this->target)();
        if (!$target instanceof Mapping) {
            throw new UnexpectedValueException('A relation target factory must return a Mapping.');
        }

        if ($target->primaryKey === null && !$this instanceof HasOne && !$this instanceof HasMany) {
            throw new InvalidArgumentException('BelongsTo and BelongsToMany targets require a primary key.');
        }

        if (!$this instanceof BelongsToMany) {
            self::assertCompatibleKeys(
                $owner->columnType($this->ownerKey($owner)),
                $target->columnType($this->targetKey($target)),
            );
        }

        return $target;
    }

    /**
     * The owner column the relation matches: the foreign key of BelongsTo, otherwise the primary key.
     *
     * @internal
     * @param Mapping<object> $owner
     */
    public function ownerKey(Mapping $owner): string
    {
        return $this instanceof BelongsTo
            ? $this->foreignKey
            : $owner->requirePrimaryKey('Matching a relation owner');
    }

    /**
     * The target column the relation matches: the foreign key of HasOne/HasMany, otherwise the primary key.
     *
     * @internal
     * @param Mapping<object> $target
     */
    public function targetKey(Mapping $target): string
    {
        return $this instanceof HasOne || $this instanceof HasMany
            ? $this->foreignKey
            : $target->requirePrimaryKey('Matching a relation target');
    }

    /**
     * Matches the qualified owner and target keys of a direct relation. The referenced primary
     * key stays on the left, because SQLite compares under the collation of the left column.
     *
     * @internal
     */
    public function keyCondition(string $ownerKey, string $targetKey): string
    {
        return $this instanceof BelongsTo ? $targetKey . ' = ' . $ownerKey : $ownerKey . ' = ' . $targetKey;
    }

    /** @internal Whether assign receives a list rather than one object or NULL. */
    public function isMany(): bool
    {
        return $this instanceof HasMany || $this instanceof BelongsToMany;
    }

    private static function assertCompatibleKeys(string $ownerType, string $targetType): void
    {
        $family = KeyType::of($ownerType);
        if ($family === null || $family !== KeyType::of($targetType)) {
            throw new InvalidArgumentException(
                'A relation foreign key and referenced primary key must have compatible types.',
            );
        }
    }
}
