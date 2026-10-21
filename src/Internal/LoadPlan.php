<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use InvalidArgumentException;
use Solo\Repository\Filter;
use Solo\Repository\Load;

/** @internal */
final readonly class LoadPlan
{
    /** @param array<string, LoadNode> $nodes */
    private function __construct(public array $nodes)
    {
    }

    /** @param array<mixed> $spec */
    public static function from(array $spec): self
    {
        if (!array_is_list($spec)) {
            throw new InvalidArgumentException('Loads must be a list of relation paths or Load objects.');
        }

        $paths = [];
        foreach ($spec as $item) {
            if (!is_string($item) && !$item instanceof Load) {
                throw new InvalidArgumentException('Loads must contain only relation paths or Load objects.');
            }
            $load = $item instanceof Load ? $item : new Load($item);
            $paths[] = [explode('.', $load->name), $item instanceof Load ? $item : null];
        }

        return self::fromPaths($paths);
    }

    /**
     * Groups paths by their first segment. A string creates the path; a Load also sets its final edge.
     *
     * @param list<array{non-empty-list<string>, Load|null}> $paths [remaining segments, explicit Load]
     */
    private static function fromPaths(array $paths): self
    {
        $explicit = [];
        $children = [];
        foreach ($paths as [$segments, $load]) {
            $segment = array_shift($segments);
            $children[$segment] ??= [];
            if ($segments !== []) {
                $children[$segment][] = [$segments, $load];
            } elseif ($load !== null) {
                $local = new Load($segment, $load->criteria, $load->orderBy, $load->visibility);
                if (isset($explicit[$segment]) && !self::sameSettings($explicit[$segment], $local)) {
                    throw new InvalidArgumentException('Conflicting explicit settings for load path ' . $load->name);
                }
                $explicit[$segment] = $local;
            }
        }

        $nodes = [];
        foreach ($children as $segment => $remaining) {
            $nodes[$segment] = new LoadNode($explicit[$segment] ?? new Load($segment), self::fromPaths($remaining));
        }

        return new self($nodes);
    }

    private static function sameSettings(Load $left, Load $right): bool
    {
        return $left->visibility === $right->visibility
            && $left->orderBy === $right->orderBy
            && self::sameCriteria($left->criteria, $right->criteria);
    }

    private static function sameCriteria(mixed $left, mixed $right): bool
    {
        if ($left instanceof Filter && $right instanceof Filter) {
            return $left->operator === $right->operator
                && $left->relation === $right->relation
                && $left->visibility === $right->visibility
                && self::sameCriteria($left->parts, $right->parts);
        }
        if (is_array($left) && is_array($right)) {
            if (count($left) !== count($right)) {
                return false;
            }
            foreach ($left as $key => $value) {
                if (!array_key_exists($key, $right) || !self::sameCriteria($value, $right[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $left === $right;
    }
}
