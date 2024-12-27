<?php

namespace Solo\Repository\Interfaces;

interface FilterableRepositoryInterface
{
    public function filter(?array $filters): self;
    public function orderBy(?string ...$order): self;
    public function page(int|string|null $page): self;
    public function perPage(int|string|null $perPage): self;
    public function primaryKey(string $primaryKey): self;
}