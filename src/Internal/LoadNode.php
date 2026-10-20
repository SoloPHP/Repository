<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

use Solo\Repository\Load;

/** @internal */
final readonly class LoadNode
{
    public function __construct(public Load $load, public LoadPlan $children)
    {
    }
}
