<?php

namespace Solo\Repository;
use Closure;

final readonly class FilterConfig
{
    public function __construct(
        public string|Closure|null $where = null,
        public ?string             $joins = null,
        public ?string             $select = null,
        public ?array              $search = null
    )
    {
    }
}