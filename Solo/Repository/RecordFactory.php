<?php

namespace Solo\Repository;

use Solo\Database;
use stdClass;

final readonly class RecordFactory
{
    public function __construct(
        private Database $db
    ) {}

    public function createEmpty(string $table): object
    {
        $this->db->query("DESCRIBE ?t", $table);
        $description = $this->db->fetchAll();
        $emptyRecord = new stdClass();

        foreach ($description as $column) {
            if ($this->shouldSkipColumn($column)) {
                continue;
            }

            $emptyRecord->{$column->Field} = $this->getDefaultValue($column);
        }

        return $emptyRecord;
    }

    private function shouldSkipColumn(object $column): bool
    {
        return $column->Key === 'PRI' ||
            in_array($column->Default, ['current_timestamp()', 'current_timestamp', 'CURRENT_TIMESTAMP'], true);
    }

    private function getDefaultValue(object $column): mixed
    {
        return match(true) {
            $column->Null === 'YES' => null,
            $column->Default !== null => $this->cast($column->Type, $column->Default),
            default => ''
        };
    }

    private function cast(string $type, $default): mixed
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