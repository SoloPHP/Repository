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

        $tree = [];
        foreach ($spec as $item) {
            if (!is_string($item) && !$item instanceof Load) {
                throw new InvalidArgumentException('Loads must contain only relation paths or Load objects.');
            }
            $explicit = $item instanceof Load;
            $load = $explicit ? $item : new Load($item);
            self::addPath($tree, explode('.', $load->name), $load, $explicit);
        }

        return self::fromTree($tree);
    }

    /**
     * @param array<string, array{load: Load, explicit: bool, children: array}> $tree
     * @param non-empty-list<string> $segments
     */
    private static function addPath(array &$tree, array $segments, Load $load, bool $explicit): void
    {
        $segment = array_shift($segments);
        $node = $tree[$segment] ?? ['load' => new Load($segment), 'explicit' => false, 'children' => []];
        if ($segments !== []) {
            self::addPath($node['children'], $segments, $load, $explicit);
        } elseif ($explicit) {
            $local = new Load($segment, $load->criteria, $load->orderBy, $load->visibility);
            if ($node['explicit'] && !self::sameSettings($node['load'], $local)) {
                throw new InvalidArgumentException('Conflicting explicit settings for load path ' . $load->name);
            }
            $node['load'] = $local;
            $node['explicit'] = true;
        }
        $tree[$segment] = $node;
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

    /** @param array<string, array{load: Load, explicit: bool, children: array}> $tree */
    private static function fromTree(array $tree): self
    {
        $nodes = [];
        foreach ($tree as $name => $node) {
            $nodes[$name] = new LoadNode($node['load'], self::fromTree($node['children']));
        }

        return new self($nodes);
    }
}
