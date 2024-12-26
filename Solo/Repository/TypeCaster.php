<?php

namespace Solo\Repository;

final readonly class TypeCaster
{
    public function cast(string $type, $default): mixed
    {
        return match (true) {
            preg_match('/tinyint\(1\)|bool|boolean/', $type) => (bool)$default,
            preg_match('/int|serial/', $type) => (int)$default,
            preg_match('/float|double|real|decimal|dec|fixed|numeric/', $type) => (float)$default,
            preg_match('/date|time|year/', $type),
            preg_match('/char|text|blob|enum|set|binary|varbinary|json/', $type) => (string)$default,
            default => $default
        };
    }
}