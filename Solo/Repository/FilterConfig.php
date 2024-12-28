<?php

namespace Solo\Repository;
use Closure;

final readonly class FilterConfig
{
    public function __construct(
        public string|Closure $where,
        public string $select = '',
        public string $joins = ''
    ) {}
}