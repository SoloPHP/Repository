<?php

declare(strict_types=1);

namespace Solo\Repository;

use RuntimeException;

/**
 * No row the read can see has the ID asked for, or the type of the key rejects the ID, such as
 * "007" for an integer key. Thrown by ReadQuery::findOrFail(); an application answers it as
 * "not found", a 404 over HTTP. The ID came from outside, so the message leaves it out; it is
 * kept in $id.
 */
final class RecordNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly string $table,
        public readonly int|string $id,
    ) {
        parent::__construct(sprintf('No visible row of "%s" has the requested ID.', $table));
    }
}
