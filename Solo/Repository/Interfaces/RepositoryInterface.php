<?php

namespace Solo\Repository\Interfaces;

interface RepositoryInterface extends FilterableRepositoryInterface
{
    public function read(bool $readOne = false): mixed;
    public function readOne(): ?object;
    public function readAll(): array;
    public function count(): int;
    public function create(array $data, bool $sanitizeFields = false): string|false;
    public function update(int|array $id, array $data, bool $sanitizeFields = false): int;
    public function delete(int $id): int;
    public function createEmptyRecord(): object;
    public function beginTransaction(): bool;
    public function commit(): bool;
    public function rollback(): bool;
}