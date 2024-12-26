<?php

namespace Solo\Repository\Interfaces;

interface RepositoryInterface
{
    public function create(array $data, bool $sanitizeFields = false): string|false;
    public function update(int|array $id, array $data, bool $sanitizeFields = false): int;
    public function delete(int $id): int;
    public function read(bool $readOne = false): mixed;
    public function readOne(): ?object;
    public function readAll(): array;
    public function count(): int;
    public function createEmptyRecord(): object;
}