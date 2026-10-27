<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Solo\Repository\Repository;
use Solo\Repository\Tests\Core\Fixtures\Note;
use Solo\Repository\Tests\Core\Fixtures\NoteRepository;

final class RepositorySubclassTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement(
            'CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, done INTEGER NOT NULL DEFAULT 0)'
        );
    }

    public function testASubclassFixesItsMappingAndAddsItsOwnMethods(): void
    {
        $notes = new NoteRepository($this->db);
        $id = $notes->insertGetId(['title' => 'Write tests']);
        $notes->insert(['title' => 'Ship', 'done' => 1]);

        $note = $notes->read()->find($id);
        self::assertInstanceOf(Note::class, $note);
        self::assertSame('Write tests', $note->title);
        self::assertSame(['Write tests'], array_map(static fn (Note $note): string => $note->title, $notes->open()));
        self::assertSame(1, $notes->update($id, ['done' => 1]));
        self::assertSame([], $notes->open());
    }

    public function testASubclassMayRedeclareOnlyTheConstructor(): void
    {
        $repository = new ReflectionClass(Repository::class);
        self::assertFalse($repository->isFinal());
        self::assertTrue($repository->isReadOnly());
        foreach ($repository->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertSame($method->getName() !== '__construct', $method->isFinal(), $method->getName());
        }
    }
}
