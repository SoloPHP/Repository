<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Database;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use RuntimeException;
use Solo\Repository\Mapping;
use Solo\Repository\Repository;
use Solo\Repository\Translations;
use Solo\Repository\TranslationStore;

final class TranslationDatabaseTest extends DatabaseTestCase
{
    private Repository $repository;
    private TranslationStore $store;
    private int $firstId;
    private int $secondId;
    private int $thirdId;

    protected function setUp(): void
    {
        parent::setUp();

        $schema = new Schema();
        $products = $schema->createTable('repo_translation_products');
        $products->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $products->setPrimaryKey(['id']);
        $products->addColumn('sku', Types::STRING, ['length' => 100]);
        $products->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $translations = $schema->createTable('repo_product_translations');
        $translations->addColumn('product_id', Types::INTEGER);
        $translations->addColumn('locale', Types::STRING, ['length' => 16]);
        $translations->addColumn('name', Types::STRING, [
            'length' => 120,
            'notnull' => false,
            'default' => 'database-name',
        ]);
        $translations->addColumn('description', Types::TEXT, ['notnull' => false]);
        $translations->addUniqueIndex(['product_id', 'locale'], 'repo_product_translations_owner_locale');
        $translations->addForeignKeyConstraint(
            'repo_translation_products',
            ['product_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'repo_product_translations_owner_fk',
        );
        $this->createSchema($schema);

        $this->repository = new Repository($this->connection, new Mapping(
            table: 'repo_translation_products',
            columns: ['id' => Types::INTEGER, 'sku' => Types::STRING, 'deleted_at' => Types::DATETIME_IMMUTABLE],
            hydrate: static fn(array $row): object => (object) $row,
            softDeleteColumn: 'deleted_at',
            translations: new Translations(
                table: 'repo_product_translations',
                foreignKey: 'product_id',
                columns: ['name' => Types::STRING, 'description' => Types::TEXT],
                defaultLocale: 'en',
            ),
        ));
        $this->store = $this->repository->translations();
        $this->firstId = $this->repository->insertGetId(['sku' => 'first']);
        $this->secondId = $this->repository->insertGetId(['sku' => 'second']);
        $this->thirdId = $this->repository->insertGetId(['sku' => 'third']);
        $this->log->reset();
    }

    public function testSeedReportsInsertedRowsAndLeavesExistingTranslations(): void
    {
        $this->store->save($this->firstId, 'en', ['name' => 'Existing']);
        $this->log->reset();

        self::assertSame(1, $this->store->seed($this->firstId, ['en', 'uk'], [
            'name' => 'Seeded',
            'description' => 'Text',
        ]));
        self::assertSame(0, $this->store->seed($this->firstId, ['en', 'uk'], ['name' => 'Again']));
        self::assertCount(2, $this->log->queries);
        self::assertSame(['name' => 'Existing', 'description' => null], $this->store->get($this->firstId, 'en'));
        self::assertSame(['name' => 'Seeded', 'description' => 'Text'], $this->store->get($this->firstId, 'uk'));
    }

    public function testNativeUpsertIsOneStatementAndAppliesPatchAndColumnDefaults(): void
    {
        $this->store->save($this->firstId, 'en', ['description' => 'Initial']);

        self::assertCount(1, $this->log->queries);
        self::assertStringStartsWith('INSERT INTO', $this->log->queries[0]['sql']);
        self::assertSame([], $this->log->selects());
        self::assertSame(['name' => 'database-name', 'description' => 'Initial'], $this->store->get($this->firstId, 'en'));

        $this->log->reset();
        $this->store->save((string) $this->firstId, 'en', ['name' => 'Стіл']);
        self::assertCount(1, $this->log->queries);
        self::assertSame([], $this->log->selects());
        self::assertSame(['name' => 'Стіл', 'description' => 'Initial'], $this->store->get($this->firstId, 'en'));

        $this->store->save($this->firstId, 'en', ['description' => null]);
        self::assertSame(['name' => 'Стіл', 'description' => null], $this->store->get($this->firstId, 'en'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM repo_product_translations'));
        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testFallbackIsSharedBySelectWhereOrderCountAndExistsWithoutMutatingDefaultLocale(): void
    {
        $this->store->save($this->firstId, 'en', ['name' => 'Charlie', 'description' => 'English first']);
        $this->store->save($this->firstId, 'uk', ['name' => 'Alpha']);
        $this->store->save($this->secondId, 'en', ['name' => 'Bravo', 'description' => 'English second']);
        // An explicit NULL, because an omitted name would take the column default instead of falling back.
        $this->store->save($this->secondId, 'uk', ['name' => null, 'description' => 'Ukrainian second']);
        $this->store->save($this->thirdId, 'en', ['name' => 'Alpha']);
        $this->store->save($this->thirdId, 'uk', ['name' => 'Charlie']);

        $default = $this->repository->read();
        $localized = $default->withLocale('uk', fallback: 'en');
        self::assertSame('Charlie', $default->find($this->firstId)->name);
        self::assertSame('Alpha', $localized->find($this->firstId)->name);
        self::assertSame('English first', $localized->find($this->firstId)->description);
        self::assertSame([
            ['id' => $this->secondId, 'name' => 'Bravo', 'description' => 'Ukrainian second'],
        ], $localized->rows(['id', 'name', 'description'], ['name' => 'Bravo']));
        self::assertSame(
            [$this->firstId, $this->secondId, $this->thirdId],
            array_column($localized->rows(['id'], orderBy: ['name' => 'ASC', 'id' => 'ASC']), 'id'),
        );
        self::assertSame(1, $localized->count(['name' => 'Bravo']));
        self::assertTrue($localized->exists(['name' => 'Bravo', 'description' => 'Ukrainian second']));
        self::assertFalse($localized->exists(['name' => 'Bravo', 'description' => 'English second']));
        self::assertSame('Charlie', $this->repository->read()->find($this->firstId)->name);
    }

    public function testFallbackPreservesEmptyAndZeroStringsAndOnlyReplacesNullPerField(): void
    {
        $this->store->save($this->firstId, 'en', ['name' => 'Fallback', 'description' => 'Base description']);
        $this->store->save($this->firstId, 'uk', ['name' => '', 'description' => null]);
        $localized = $this->repository->withLocale('uk', fallback: 'en');

        self::assertSame('', $localized->find($this->firstId)->name);
        self::assertSame('Base description', $localized->find($this->firstId)->description);
        self::assertTrue($localized->exists(['id' => $this->firstId, 'name' => '']));
        $this->store->save($this->firstId, 'uk', ['name' => '0']);
        self::assertSame('0', $localized->find($this->firstId)->name);
        $this->store->save($this->firstId, 'uk', ['name' => null]);
        self::assertSame('Fallback', $localized->find($this->firstId)->name);
        self::assertSame(['name' => null, 'description' => null], $this->store->get($this->firstId, 'uk'));
        self::assertNull($this->store->get($this->firstId, 'fr'));
        self::assertSame([
            ['name' => null, 'description' => null],
        ], $this->repository->withLocale('fr', fallback: 'de')->rows(
            ['name', 'description'],
            ['id' => $this->firstId],
        ));
    }

    public function testCallerTransactionRollsBackBasePatchAndBothUpsertBranches(): void
    {
        $this->store->save($this->firstId, 'en', ['name' => 'Original']);

        try {
            $this->connection->transactional(function (): void {
                $this->repository->update($this->firstId, ['sku' => 'changed']);
                $this->store->save($this->firstId, 'en', ['name' => 'Changed']);
                $this->store->save($this->firstId, 'uk', ['name' => 'New']);
                throw new RuntimeException('abort translation transaction');
            });
            self::fail('Expected the caller to abort the transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('abort translation transaction', $exception->getMessage());
        }

        self::assertSame('first', $this->repository->read()->find($this->firstId)->sku);
        self::assertSame(['name' => 'Original', 'description' => null], $this->store->get($this->firstId, 'en'));
        self::assertNull($this->store->get($this->firstId, 'uk'));
        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testForeignKeyViolationPropagatesAndRollsBackEarlierTranslationWrite(): void
    {
        $this->store->save($this->firstId, 'en', ['name' => 'Original']);
        $missingId = $this->thirdId + 1000;

        try {
            $this->connection->transactional(function () use ($missingId): void {
                $this->store->save($this->firstId, 'uk', ['name' => 'Must roll back']);
                $this->store->save($missingId, 'en', ['name' => 'Missing owner']);
            });
            self::fail('Expected a foreign key violation.');
        } catch (ForeignKeyConstraintViolationException) {
            self::assertFalse($this->connection->isTransactionActive());
            self::assertNull($this->store->get($this->firstId, 'uk'));
            self::assertNull($this->store->get($missingId, 'en'));
            self::assertSame(['name' => 'Original', 'description' => null], $this->store->get($this->firstId, 'en'));
        }
    }

    public function testPhysicalTranslationWritesIgnoreOwnerVisibilityAndDeleteOnlyOneLocale(): void
    {
        $this->store->save($this->firstId, 'en', ['name' => 'English']);
        $this->store->save($this->firstId, 'uk', ['name' => 'Ukrainian']);
        $this->store->save($this->secondId, 'en', ['name' => 'Other owner']);
        self::assertSame(1, $this->repository->delete($this->firstId));
        $this->store->save($this->firstId, 'en', ['name' => 'Edited while deleted']);

        self::assertNull($this->repository->read()->find($this->firstId));
        self::assertSame(1, $this->store->delete($this->firstId, 'uk'));
        self::assertSame(0, $this->store->delete($this->firstId, 'uk'));
        self::assertNull($this->store->get($this->firstId, 'uk'));
        self::assertSame(
            ['name' => 'Edited while deleted', 'description' => null],
            $this->store->get($this->firstId, 'en'),
        );
        self::assertSame(['name' => 'Other owner', 'description' => null], $this->store->get($this->secondId, 'en'));

        $this->log->reset();
        $this->store->save($this->firstId, 'fr', []);
        self::assertSame([], $this->log->statements());
        self::assertNull($this->store->get($this->firstId, 'fr'));
    }
}
