<?php

namespace Solo\Repository\Interfaces;

interface QueryBuilderInterface
{
    public function filter(?array $filters): self;
    public function orderBy(?string ...$order): self;
    public function page(?int $page): self;
    public function perPage(?int $perPage): self;
    public function limit(int $page, int $perPage): self;
    public function primaryKey(string $primaryKey): self;
}