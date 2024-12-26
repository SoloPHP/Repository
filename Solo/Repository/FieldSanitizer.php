<?php

namespace Solo\Repository;

use Solo\Database;

final readonly class FieldSanitizer
{
    public function __construct(
        private Database $db
    ) {}

    public function sanitize(string $table, array $data): array
    {
        $this->db->query("DESCRIBE ?t", $table);
        $fields = array_column($this->db->fetchAll(), 'Field');
        return array_intersect_key($data, array_flip($fields));
    }
}