<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Core;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Repository\Filter;
use Solo\Repository\Internal\Database;
use Solo\Repository\Internal\FieldResolver;
use Solo\Repository\Internal\PredicateCompiler;
use Solo\Repository\Mapping;

final class PredicateCompilerTest extends TestCase
{
    private Connection $connection;
    private Database $db;
    private Mapping $mapping;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db = new Database($this->connection, 900);
        $this->mapping = new Mapping(
            table: 'items',
            columns: [
                'id' => Types::INTEGER,
                'name' => Types::STRING,
                'price' => Types::DECIMAL,
                'enabled' => Types::BOOLEAN,
                'note' => Types::STRING,
                'created_at' => Types::DATETIME_IMMUTABLE,
            ],
            hydrate: static fn (array $row): object => (object) $row,
        );
        $this->connection->executeStatement(
            'CREATE TABLE items ('
            . 'id INTEGER PRIMARY KEY, name TEXT NOT NULL, price DECIMAL(10, 2), '
            . 'enabled BOOLEAN NOT NULL, note TEXT, created_at DATETIME NOT NULL)',
        );

        $rows = [
            [1, 'Alpha', '10.50', true, null],
            [2, 'Beta', '20.00', false, ''],
            [3, 'Gamma', null, true, 'green'],
            [4, "x' OR 1 = 1 --", '40.00', true, 'blue'],
            [5, '100%_match', '5.00', false, '0'],
        ];

        foreach ($rows as [$id, $name, $price, $enabled, $note]) {
            $this->connection->insert('items', [
                'id' => $id,
                'name' => $name,
                'price' => $price,
                'enabled' => $enabled,
                'note' => $note,
                'created_at' => new DateTimeImmutable('2026-01-0' . $id . ' 12:00:00'),
            ], $this->mapping->columns);
        }
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[DataProvider('comparisonCases')]
    public function testSupportedOperatorsExecuteOnSqlite(array $criteria, array $expectedIds): void
    {
        self::assertSame($expectedIds, $this->selectIds($criteria));
    }

    public static function comparisonCases(): iterable
    {
        yield 'implicit equality' => [['name' => 'Alpha'], [1]];
        yield 'explicit equality' => [['name =' => 'Beta'], [2]];
        yield 'inequality and boolean conversion' => [['enabled !=' => true], [2, 5]];
        yield 'greater' => [['price >' => '20.00'], [4]];
        yield 'greater or equal' => [['price >=' => '20.00'], [2, 4]];
        yield 'less' => [['price <' => '10.50'], [5]];
        yield 'less or equal' => [['price <=' => '10.50'], [1, 5]];
        yield 'in' => [['id IN' => [1, 3]], [1, 3]];
        yield 'not in' => [['id NOT IN' => [1, 3]], [2, 4, 5]];
        yield 'like' => [['name LIKE' => 'A%'], [1]];
        yield 'not like' => [['name NOT LIKE' => 'A%'], [2, 3, 4, 5]];
        yield 'null equality' => [['note' => null], [1]];
        yield 'null inequality' => [['note !=' => null], [2, 3, 4, 5]];
        yield 'empty string is a value' => [['note' => ''], [2]];
        yield 'zero string is a value' => [['note' => '0'], [5]];
        yield 'array leaves are and' => [['price >=' => '10.50', 'price <=' => '20.00'], [1, 2]];
        yield 'empty in' => [['id IN' => []], []];
        yield 'empty not in' => [['id NOT IN' => []], [1, 2, 3, 4, 5]];
    }

    public function testNestedGroupsPreserveTheirMeaningAndRepeatedFields(): void
    {
        $criteria = Filter::all(
            Filter::any(['name' => 'Alpha'], ['price >=' => '20.00']),
            ['enabled' => true],
            Filter::not(Filter::any(['id' => 2], ['id' => 3])),
        );

        self::assertSame([1, 4], $this->selectIds($criteria));
    }

    public function testNotRetainsSqlThreeValuedLogic(): void
    {
        self::assertSame([5], $this->selectIds(Filter::not(['price >' => '10.00'])));
        self::assertSame([3, 5], $this->selectIds(Filter::any(
            Filter::not(['price >' => '10.00']),
            ['price' => null],
        )));
    }

    public function testValuesAreBoundAndCannotChangeThePredicate(): void
    {
        $value = "x' OR 1 = 1 --";
        $builder = $this->builder();
        $sql = $this->compiler($builder)->compile(['name' => $value]);

        self::assertIsString($sql);
        self::assertStringNotContainsString($value, $sql);
        self::assertSame([$value], array_values($builder->getParameters()));
        self::assertSame([Types::STRING], array_values($builder->getParameterTypes()));
        self::assertSame([4], $this->executeIds($builder->where($sql)));
    }

    public function testDbalTypesAreKeptUntilExecutionIncludingEveryInElement(): void
    {
        $date = new DateTimeImmutable('2026-01-03 12:00:00');
        $builder = $this->builder();
        $sql = $this->compiler($builder)->compile([
            'created_at >=' => $date,
            'id IN' => [3, 4, 5],
            'enabled' => true,
        ]);

        self::assertIsString($sql);
        self::assertSame($date, array_values($builder->getParameters())[0]);
        self::assertSame([
            Types::DATETIME_IMMUTABLE,
            Types::INTEGER,
            Types::INTEGER,
            Types::INTEGER,
            Types::BOOLEAN,
        ], array_values($builder->getParameterTypes()));
        self::assertSame([3, 4], $this->executeIds($builder->where($sql)));
    }

    #[DataProvider('invalidCriteria')]
    public function testInvalidCriteriaAreRejectedBeforeBinding(array|Filter $criteria): void
    {
        $builder = $this->builder();
        $builder->setParameter('existing', 7, Types::INTEGER);

        try {
            $this->compiler($builder)->compile($criteria);
            self::fail('Expected invalid criteria to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame(['existing' => 7], $builder->getParameters());
        }
    }

    public static function invalidCriteria(): iterable
    {
        yield 'unknown field' => [['unknown' => 1]];
        yield 'qualified field' => [['items.id' => 1]];
        yield 'sql expression' => [['id OR 1 = 1 --' => 1]];
        yield 'unsupported operator' => [['id BETWEEN' => [1, 2]]];
        yield 'unsupported inequality alias' => [['id <>' => 1]];
        yield 'operator missing separator' => [['id>=' => 1]];
        yield 'integer key' => [[['id' => 1]]];
        yield 'legacy group key' => [['OR' => [['id' => 1]]]];
        yield 'array without in' => [['id' => [1, 2]]];
        yield 'array with equality' => [['id =' => [1]]];
        yield 'scalar in' => [['id IN' => 1]];
        yield 'null in' => [['id IN' => [1, null]]];
        yield 'nested array in' => [['id IN' => [[1]]]];
        yield 'null not in' => [['id NOT IN' => [null]]];
        yield 'null greater' => [['price >' => null]];
        yield 'null like' => [['name LIKE' => null]];
        yield 'invalid later leaf' => [['name' => 'Alpha', 'unknown' => 1]];
        yield 'invalid array branch after false' => [['id IN' => [], 'unknown' => 1]];
        yield 'invalid all branch after false' => [Filter::all(['id IN' => []], ['unknown' => 1])];
        yield 'invalid any branch after true' => [Filter::any([], ['name LIKE' => null])];
        yield 'invalid not branch under true' => [Filter::any([], Filter::not(['unknown' => 1]))];
        yield 'invalid field in empty in' => [['unknown IN' => []]];
    }

    #[DataProvider('mistypedValues')]
    public function testValuesMustFitTheFieldTypeBeforeBinding(array|Filter $criteria, string $message): void
    {
        $builder = $this->builder();

        try {
            $this->compiler($builder)->compile($criteria);
            self::fail('Expected a value the driver would coerce to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertSame([], $builder->getParameters());
        }
    }

    public static function mistypedValues(): iterable
    {
        $integer = 'Field "id" requires an integer or a canonical decimal string.';
        yield 'letters' => [['id' => 'abc'], $integer];
        yield 'numeric prefix' => [['id' => '1abc'], $integer];
        yield 'leading zero' => [['id' => '01'], $integer];
        yield 'whitespace' => [['id' => ' 1'], $integer];
        yield 'float' => [['id >' => 1.5], $integer];
        yield 'boolean' => [['id' => true], $integer];
        yield 'outside the PHP integer range' => [['id' => '9223372036854775808'], $integer];
        yield 'in element' => [['id IN' => [1, '2x']], $integer];
        yield 'like pattern' => [['id LIKE' => '1%'], $integer];
        yield 'negated group' => [Filter::not(['id' => 'x']), $integer];
        $boolean = 'Field "enabled" requires a boolean, 0 or 1.';
        yield 'boolean word' => [['enabled' => 'false'], $boolean];
        yield 'boolean two' => [['enabled' => 2], $boolean];
        $number = 'Field "price" requires a finite number or a decimal string.';
        yield 'decimal letters' => [['price >=' => 'cheap'], $number];
        yield 'decimal exponent' => [['price <' => '1e999'], $number];
        yield 'decimal infinity' => [['price <' => INF], $number];
        yield 'decimal boolean' => [['price' => false], $number];
        $string = 'Field "name" requires a string, an integer or a Stringable.';
        yield 'string boolean' => [['name' => true], $string];
        yield 'string float' => [['name' => 1.5], $string];
    }

    public function testValuesInTheirTypeFormsAreAccepted(): void
    {
        self::assertSame([1, 4], $this->selectIds(['id IN' => [1, '4', '-7'], 'enabled' => '1']));
        self::assertSame([2, 5], $this->selectIds(['enabled' => 0]));
        self::assertSame([1, 2, 4], $this->selectIds(['price >=' => '10.50']));
        self::assertSame([5], $this->selectIds(['price <' => 6]));
        self::assertSame([5], $this->selectIds(['note' => 0]));
    }

    #[DataProvider('constantCriteria')]
    public function testConstantsNormalizeForWriteGuardsWithoutUnusedParameters(
        array|Filter $criteria,
        bool $expected,
    ): void {
        $builder = $this->builder();

        self::assertSame($expected, $this->compiler($builder)->compile($criteria));
        self::assertSame([], $builder->getParameters());
    }

    public static function constantCriteria(): iterable
    {
        yield 'empty criteria' => [[], true];
        yield 'empty in' => [['id IN' => []], false];
        yield 'empty not in' => [['id NOT IN' => []], true];
        yield 'not true' => [Filter::not([]), false];
        yield 'not false' => [Filter::not(['id IN' => []]), true];
        yield 'and drops unused bindings' => [Filter::all(['name' => 'Alpha'], ['id IN' => []]), false];
        yield 'or drops unused bindings' => [Filter::any(['name' => 'Alpha'], []), true];
        yield 'nested normalization' => [Filter::not(Filter::any(['id IN' => []], ['id IN' => []])), true];
    }

    public function testIdentityConstantsLeaveOnlyTheRequiredBindings(): void
    {
        $builder = $this->builder();
        $sql = $this->compiler($builder)->compile(Filter::all(
            [],
            Filter::any(['id IN' => []], ['name' => 'Alpha']),
        ));

        self::assertIsString($sql);
        self::assertSame(['Alpha'], array_values($builder->getParameters()));
        self::assertSame([1], $this->executeIds($builder->where($sql)));
    }

    #[DataProvider('emptyGroupConstructors')]
    public function testAllAndAnyRequireAtLeastOnePart(string $method): void
    {
        $this->expectException(InvalidArgumentException::class);
        Filter::$method();
    }

    public static function emptyGroupConstructors(): iterable
    {
        yield 'all' => ['all'];
        yield 'any' => ['any'];
    }

    public function testRepeatedCompilersDoNotOverwriteExistingPlaceholders(): void
    {
        $builder = $this->builder();
        $builder->setParameter('predicate1', 1, Types::INTEGER);
        $builder->setParameter('dcValue1', 1, Types::INTEGER);
        $builder->where(':predicate1 = :dcValue1');

        $first = $this->compiler($builder)->compile(['enabled' => true]);
        $second = $this->compiler($builder)->compile(['id >' => 1]);

        self::assertIsString($first);
        self::assertIsString($second);
        self::assertSame([
            'predicate1' => 1,
            'dcValue1' => 1,
            'predicate2' => true,
            'predicate3' => 1,
        ], $builder->getParameters());
        self::assertSame([3, 4], $this->executeIds($builder->andWhere($first)->andWhere($second)));
    }

    public function testParameterLimitIncludesExistingBuilderParameters(): void
    {
        $builder = $this->builder();
        $builder->setParameter('existing', 'Alpha', Types::STRING);
        $builder->where('i.name = :existing');
        $sql = $this->compiler($builder)->compile(['id IN' => range(1, 899)]);

        self::assertIsString($sql);
        self::assertCount(900, $builder->getParameters());
        self::assertSame([1], $this->executeIds($builder->andWhere($sql)));
    }

    public function testParameterLimitRejectsBeforeAddingAnyBindings(): void
    {
        $builder = $this->builder();
        $builder->setParameter('existing', 7, Types::INTEGER);

        try {
            $this->compiler($builder)->compile(['id IN' => range(1, 900)]);
            self::fail('Expected the total parameter count to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('parameter limit', $exception->getMessage());
            self::assertSame(['existing' => 7], $builder->getParameters());
        }
    }

    public function testUnqualifiedPredicateCanBeUsedInAnUpdate(): void
    {
        $builder = $this->connection->createQueryBuilder()->update('items')->set('note', ':note');
        $builder->setParameter('note', 'changed', Types::STRING);
        $sql = (new PredicateCompiler($this->db, $this->mapping, $builder))->compile(['id' => 2]);

        self::assertIsString($sql);
        self::assertSame(1, $builder->where($sql)->executeStatement());
        self::assertSame([2], $this->selectIds(['note' => 'changed']));
    }

    private function builder(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()->select('i.id')->from('items', 'i')->orderBy('i.id');
    }

    private function compiler(QueryBuilder $builder): PredicateCompiler
    {
        return new PredicateCompiler(
            $this->db,
            $this->mapping,
            $builder,
            new FieldResolver($this->db, $this->mapping, $builder, 'i', null),
        );
    }

    private function selectIds(array|Filter $criteria): array
    {
        $builder = $this->builder();
        $sql = $this->compiler($builder)->compile($criteria);
        $builder->where(is_bool($sql) ? ($sql ? '1 = 1' : '1 = 0') : $sql);

        return $this->executeIds($builder);
    }

    private function executeIds(QueryBuilder $builder): array
    {
        return array_map('intval', $builder->executeQuery()->fetchFirstColumn());
    }
}
