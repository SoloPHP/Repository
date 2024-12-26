<?php

namespace Solo\Repository;

use Solo\Database;
use stdClass;

final readonly class RecordFactory
{
    public function __construct(
        private Database $db,
        private TypeCaster $typeCaster
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
            $column->Default !== null => $this->typeCaster->cast($column->Type, $column->Default),
            default => ''
        };
    }
}