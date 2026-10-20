<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Repository\Internal\Locale;
use Solo\Repository\Internal\RowMapper;
use Solo\Repository\Mapping;
use Solo\Repository\Translations;
use stdClass;

final class TranslationsMappingTest extends TestCase
{
    public function testTranslationsPreserveConfiguredNamesAndLocale(): void
    {
        $translations = new Translations(
            table: 'product_translations',
            foreignKey: 'product_id',
            columns: ['name' => Types::STRING, 'description' => Types::TEXT],
            defaultLocale: ' en ',
            localeColumn: 'language',
        );

        self::assertSame('product_translations', $translations->table);
        self::assertSame('product_id', $translations->foreignKey);
        self::assertSame(['name' => Types::STRING, 'description' => Types::TEXT], $translations->columns);
        self::assertSame(' en ', $translations->defaultLocale);
        self::assertSame('language', $translations->localeColumn);
    }

    #[DataProvider('invalidMetadata')]
    public function testInvalidTranslationMetadataIsRejected(array $configuration): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Translations(...array_replace([
            'table' => 'product_translations',
            'foreignKey' => 'product_id',
            'columns' => ['name' => Types::STRING],
            'defaultLocale' => 'en',
        ], $configuration));
    }

    public static function invalidMetadata(): iterable
    {
        yield 'empty table' => [['table' => '']];
        yield 'qualified table' => [['table' => 'public.product_translations']];
        yield 'table SQL' => [['table' => 'translations; DROP TABLE products']];
        yield 'invalid foreign key' => [['foreignKey' => 'product.id']];
        yield 'invalid locale column' => [['localeColumn' => 'locale DESC']];
        yield 'same key and locale column' => [['foreignKey' => 'locale']];
        yield 'empty columns' => [['columns' => []]];
        yield 'numeric column key' => [['columns' => [Types::STRING]]];
        yield 'invalid translated column' => [['columns' => ['name.title' => Types::STRING]]];
        yield 'foreign key as translated column' => [['columns' => ['product_id' => Types::STRING]]];
        yield 'locale as translated column' => [['columns' => ['locale' => Types::STRING]]];
        yield 'custom locale as translated column' => [[
            'localeColumn' => 'language',
            'columns' => ['language' => Types::TEXT],
        ]];
        yield 'integer translation' => [['columns' => ['name' => Types::INTEGER]]];
        yield 'JSON translation' => [['columns' => ['name' => Types::JSON]]];
        yield 'ASCII translation' => [['columns' => ['name' => Types::ASCII_STRING]]];
        yield 'unknown type' => [['columns' => ['name' => 'repo_unknown_translation_type']]];
        yield 'type instance' => [['columns' => ['name' => Type::getType(Types::STRING)]]];
        yield 'null type' => [['columns' => ['name' => null]]];
        yield 'empty default locale' => [['defaultLocale' => '']];
        yield 'blank default locale' => [['defaultLocale' => " \t\n"]];
    }

    public function testTranslatedFieldsAreReadOnlyThroughTheBaseMapping(): void
    {
        $mapping = $this->mapping();

        self::assertSame(Types::INTEGER, $mapping->fieldType('id'));
        self::assertSame(Types::STRING, $mapping->fieldType('name'));
        self::assertSame(Types::TEXT, $mapping->fieldType('description'));
        self::assertSame([
            'id' => Types::INTEGER,
            'sku' => Types::STRING,
            'name' => Types::STRING,
            'description' => Types::TEXT,
        ], $mapping->readColumns());
        self::assertSame(['id' => Types::INTEGER, 'sku' => Types::STRING], $mapping->columns);
        self::assertSame('locale', $mapping->translations->localeColumn);

        $this->expectException(InvalidArgumentException::class);
        $mapping->columnType('name');
    }

    public function testPlainMappingRetainsItsReadColumns(): void
    {
        $mapping = new Mapping('products', ['id' => Types::INTEGER], static fn (array $row): object => (object) $row);

        self::assertNull($mapping->translations);
        self::assertSame(['id' => Types::INTEGER], $mapping->readColumns());
        self::assertSame(Types::INTEGER, $mapping->fieldType('id'));
    }

    #[DataProvider('unmappedFields')]
    public function testFieldTypeRejectsUnmappedAndTranslationServiceFields(string $field): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->mapping()->fieldType($field);
    }

    public static function unmappedFields(): iterable
    {
        yield ['missing'];
        yield ['product_id'];
        yield ['locale'];
    }

    public function testTranslatedColumnsCannotShadowBaseColumns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not overlap');

        new Mapping(
            table: 'products',
            columns: ['id' => Types::INTEGER, 'name' => Types::STRING],
            hydrate: static fn (array $row): object => (object) $row,
            translations: new Translations('product_translations', 'product_id', ['name' => Types::TEXT], 'en'),
        );
    }

    public function testRowMapperConvertsTranslatedTextBeforeHydration(): void
    {
        $mapper = new RowMapper($this->mapping(), new SQLitePlatform());
        $description = fopen('php://memory', 'r+');
        self::assertIsResource($description);
        fwrite($description, 'Translated description');
        rewind($description);

        try {
            $product = $mapper->hydrate([
                'id' => '42',
                'sku' => 'chair-42',
                'name' => 'Стілець',
                'description' => $description,
            ]);

            self::assertSame(42, $product->id);
            self::assertSame('chair-42', $product->sku);
            self::assertSame('Стілець', $product->name);
            self::assertSame('Translated description', $product->description);
        } finally {
            fclose($description);
        }
    }

    public function testPartialTranslatedRowsPreserveNullAndEmptyValues(): void
    {
        $mapper = new RowMapper($this->mapping(), new SQLitePlatform());

        self::assertSame(['name' => null, 'description' => null], $mapper->convert([
            'name' => null,
            'description' => null,
        ]));
        self::assertSame(['name' => '', 'description' => '0'], $mapper->convert([
            'name' => '',
            'description' => '0',
        ]));
    }

    #[DataProvider('validLocales')]
    public function testLocaleKeepsExactValuesAndCollapsesMatchingFallback(
        string $locale,
        ?string $fallback,
        ?string $expectedFallback,
    ): void {
        $pair = new Locale($locale, $fallback);

        self::assertSame($locale, $pair->locale);
        self::assertSame($expectedFallback, $pair->fallback);
    }

    public static function validLocales(): iterable
    {
        yield 'default fallback' => ['en', null, null];
        yield 'two languages' => ['uk', 'en', 'en'];
        yield 'same language' => ['uk', 'uk', null];
        yield 'case remains significant' => ['en', 'EN', 'EN'];
        yield 'whitespace preserved' => [' en ', 'en', 'en'];
        yield 'same exact whitespace' => [' en ', ' en ', null];
        yield 'literal zero' => ['0', null, null];
    }

    #[DataProvider('invalidLocales')]
    public function testLocaleRejectsEmptyValues(string $locale, ?string $fallback): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Locale($locale, $fallback);
    }

    public static function invalidLocales(): iterable
    {
        yield 'empty primary' => ['', null];
        yield 'blank primary' => [" \t\r\n", 'en'];
        yield 'empty fallback' => ['uk', ''];
        yield 'blank fallback' => ['uk', " \t\r\n"];
        yield 'both empty' => ['', ''];
    }

    /** @return Mapping<stdClass> */
    private function mapping(): Mapping
    {
        return new Mapping(
            table: 'products',
            columns: ['id' => Types::INTEGER, 'sku' => Types::STRING],
            hydrate: static fn (array $row): stdClass => (object) $row,
            translations: new Translations(
                table: 'product_translations',
                foreignKey: 'product_id',
                columns: ['name' => Types::STRING, 'description' => Types::TEXT],
                defaultLocale: 'en',
            ),
        );
    }
}
