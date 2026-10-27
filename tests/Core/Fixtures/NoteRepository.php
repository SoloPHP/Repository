<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Solo\Repository\Mapping;
use Solo\Repository\Repository;

/**
 * A typed repository: the mapping is fixed in the constructor, so a container
 * can inject it by class, and the table's own queries live beside it.
 *
 * @extends Repository<Note>
 */
final readonly class NoteRepository extends Repository
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection, self::mapping());
    }

    /** @return Mapping<Note> */
    public static function mapping(): Mapping
    {
        return new Mapping(
            table: 'notes',
            columns: ['id' => Types::INTEGER, 'title' => Types::STRING, 'done' => Types::SMALLINT],
            hydrate: static fn (array $row): Note => new Note(...$row),
        );
    }

    /** @return list<Note> */
    public function open(): array
    {
        return $this->read()->findBy(['done' => 0], ['id' => 'ASC']);
    }
}
