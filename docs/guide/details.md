# Руководство Solo Repository

Статус: первая версия **1.0.0** отдельного пакета `solophp/repository` готовится к релизу. Namespace — `Solo\Repository`. Пакет ещё не опубликован. Для приложения на `solophp/base-repository` v3 используйте [руководство по миграции](migration.md); до завершения перехода сохраняйте старую зависимость `^3.0`. [Публичный API](api.md) и [быстрый старт](index.md) описывают реализованный контракт.

## Реализовано

Доступны `Mapping`, `Repository`, `ReadQuery`, `Filter::all/any/not/related`, `Visibility`, `Translations`, `TranslationStore`, четыре вида связей, `Load` и `LinkSet`. Реализованы чтение моделей и проекций, count/exists, CRUD, атомарный insertMany, soft delete, пакетный обход по ID, переводы с единым fallback и пакетная загрузка вложенных связей. Исполняемые примеры `examples/crud.php`, `examples/translations.php` и `examples/relations.php` используют SQLite в памяти:

```sh
composer install
php examples/crud.php
php examples/translations.php
php examples/relations.php
vendor/bin/phpunit --testsuite Core
composer test
```

`Mapping` принимает `translations` и `relations`. `withLocale()`, `with()` и `withVisibility()` возвращают независимые настройки чтения. `translations()` и `links()` дают отдельный API записи. Перед стабильным тегом остаётся проверить миграцию реального приложения; синтетические сценарии каталога и экспорта уже входят в интеграционные тесты.

SQL использует консервативный бюджет 900 параметров на statement, включая locale-параметры JOIN; keyset оставляет один из них для продолжения. Имя таблицы пока простое, без schema-prefix. ID целочисленного PK принимается как int или каноническая десятичная строка: нечисловые значения и переполнение PHP integer для integer/smallint отклоняются. Это исключает превращение ошибочного ID в чужой числовой ключ.

Основной набор тестов использует SQLite. Отдельный набор `Database` выполняется на реальных MySQL 8.4 и PostgreSQL 17 с native PDO prepares. Он запускается отдельно от `composer test`: отсутствие URL, недоступная БД и пропущенные тесты считаются ошибками.

Solo Repository превращает строки БД в обычные PHP-объекты, разрешает переводы с fallback и загружает связи пакетами. Doctrine DBAL остаётся SQL API. Пакет не отслеживает изменения объектов и не сохраняет граф автоматически.

## Проверка на MySQL и PostgreSQL

Общий набор из 24 тестов проверяет generated ID и CRUD, преобразование decimal/bool/date/JSON, soft delete и keyset, native PATCH upsert переводов, единый fallback, четыре типа связей и вложенные EXISTS. Отдельные сценарии проверяют пакетные границы, сортировку коллекции из 905 элементов, освобождение предыдущей страницы, строковые ключи и native PostgreSQL UUID, rollback позднего пакета и продолжение внешней транзакции после savepoint. Схемы содержат настоящие FK и UNIQUE; тесты выполняют SQL на серверах без моков платформ.

`.github/workflows/tests.yml` сохраняет три задания основного SQLite-набора и запускает `Database` на каждой из двух СУБД для тех же комбинаций:

| PHP | Ограничение DBAL при установке |
|---|---|
| 8.3 | `4.3.*` — минимальная поддерживаемая ветка |
| 8.3 | `^4.3` — актуальная совместимая версия |
| 8.5 | `^4.3` — актуальная совместимая версия |

Для своей тестовой БД нужен `REPOSITORY_DATABASE_URL` и соответствующее расширение `pdo_mysql` или `pdo_pgsql`. Поддерживаются URL `mysql://` и `postgresql://`; имя БД должно заканчиваться на `_test`. Набор создаёт и удаляет свои fixture-таблицы с префиксом `repo_`, поэтому ему нужна отдельная тестовая БД:

```sh
REPOSITORY_DATABASE_URL='mysql://repository:repository@127.0.0.1:3306/repository_test' composer test:database
REPOSITORY_DATABASE_URL='postgresql://repository:repository@127.0.0.1:5432/repository_test' composer test:database
```

Локально достаточно Docker с Compose v2. `compose.database.yml` создаёт проект `solo-repository-database`, MySQL 8.4, PostgreSQL 17 и PHP runner. Порты на хост не публикуются, данные обеих БД находятся в tmpfs. Runner монтирует репозиторий только для чтения; зависимости устанавливаются отдельной командой до запуска тестов:

```sh
docker compose -f compose.database.yml build tests
docker run --rm -v "$PWD":/workspace -w /workspace \
  solo-repository-database-tests:local composer install --no-interaction --prefer-dist

# MySQL — URL по умолчанию.
docker compose -f compose.database.yml run --rm tests

# PostgreSQL — тот же набор, другой URL.
REPOSITORY_DATABASE_URL='postgresql://repository:repository@postgres:5432/repository_test' \
  docker compose -f compose.database.yml run --rm tests

# Удалить контейнеры, сеть и временные данные.
docker compose -f compose.database.yml down --volumes --remove-orphans
```

Compose дожидается healthcheck обеих БД. Команда runner — `php vendor/bin/phpunit -c phpunit.database.xml --do-not-cache-result`; ошибки соединения не превращаются в skip. Для выбора PHP передайте `PHP_VERSION=8.5` при сборке. Для локальной проверки минимальной ветки DBAL можно вместо `composer install` выполнить в том же runner `composer update --with='doctrine/dbal:4.3.*'`; это изменит локальные `composer.lock` и `vendor`. После такой проверки верните нужную версию зависимостей.

MySQL 8.4 и PostgreSQL 17 — проверяемые серверные платформы Solo Repository. MariaDB и другие платформы пока не входят в эту интеграционную матрицу.

## Основные решения

| Область | Решение |
|---|---|
| Основа | PHP ≥ 8.3, Doctrine DBAL 4.x с исходным минимумом 4.3. Без новых runtime-зависимостей |
| Модель | Любой объект; явная функция `hydrate(array): object`. Базовый `Entity` и reflection не нужны |
| Конфигурация | Один неизменяемый `Mapping`: таблица, типы колонок, ключ, mapper и необязательные возможности |
| Чтение | `Repository` создаёт независимый `ReadQuery`; `withLocale()` и `with()` сохраняются |
| Запись | Явные операции над данными; результат — affected rows либо явно запрошенный ID. Автоматического перечитывания модели нет |
| Переводы | Один resolver для значения в SELECT, фильтре и сортировке; fallback по каждому полю |
| Связи | Явные определения и функции присвоения; пакетная загрузка вместо запросов из объектов |
| Сложный SQL | Настоящий DBAL `QueryBuilder` через уже имеющийся `Connection` |

Не входят в 1.0: Unit of Work, identity map, lazy proxies, dirty tracking, каскадное сохранение графа, генерация схемы, query cache, event bus, advisory locks, собственный контейнер и отдельные Composer-пакеты. Приложение определяет бизнес-операции, транзакции между несколькими репозиториями и правила повторных попыток.

## Устройство

```text
Repository<T> ── Mapping<T> + Connection
   │
   ├── read() / withLocale() / with() → ReadQuery<T>
   │      └── terminal → DBAL QueryBuilder → typed rows → hydrate
   │                                              └── batch relations → assign
   │
   ├── insert / update / delete → DBAL
   ├── translations() → TranslationStore
   └── links(name) → LinkSet
```

Публичные типы: `Repository<T>`, `Mapping<T>`, `ReadQuery<T>`, `Filter`, `Visibility`, `Load`, `Translations`, четыре определения связей, `TranslationStore` и `LinkSet`. Тип `T` задаётся PHPDoc generics для статического анализа; runtime проверяет, что mapper и assign возвращают объект. Namespace `Internal` и методы с `@internal` не являются поддерживаемым API приложения.

Внутри достаточно небольших компонентов по ответственности:

- `ReadExecutor`: создаёт новый DBAL builder для каждого выполнения.
- `PredicateCompiler`: переводит ограниченные фильтры в выражения и параметры DBAL.
- `FieldResolver`: проверяет колонку и возвращает обычное либо локализованное SQL-выражение.
- `RowMapper`: применяет типы DBAL и вызывает пользовательский mapper.
- `RelationLoader`: группирует строки, пакетно получает связи и вызывает `assign`.
- `Repository`, `TranslationStore` и `LinkSet`: явные операции записи, включая узкие платформенные варианты upsert и получения generated ID.

У пакета нет собственного общего SQL AST, диалектного движка и публичных методов `select/from/join/groupBy/having`. Для таких запросов приложение использует DBAL напрямую.

## Mapping и модели

`Mapping` содержит `table`, `columns`, `hydrate`, `primaryKey = 'id'`, `generatedId = true`, а также необязательные `translations`, `relations` и `softDeleteColumn`.

`columns` — ассоциативный массив `имя_колонки => DBAL type name`. Эти имена используются в фильтрах, сортировке, записи и строковых результатах. Это одновременно allowlist: неизвестное поле вызывает ошибку до SQL. CamelCase, приватные свойства и конструкторы остаются задачей mapper. SQL-идентификаторы проверяются и экранируются средствами DBAL; значения всегда передаются параметрами.

Поддерживается один первичный ключ `int|string`. Составные ключи и связи по альтернативным уникальным ключам остаются за границами 1.0. Mapper получает все объявленные основные и переведённые колонки, а не `SELECT *`.

PK обязательно входит в `columns`; FK связи входит в `columns` соответствующей стороны. `generatedId: true` допустим только для совместимого целочисленного PK. Собственный Mapping проверяется сразу, требования к ленивой целевой стороне — при первом разрешении связи.

После чтения типы преобразуются через DBAL перед вызовом `hydrate`. Например, `decimal` остаётся строкой, а `datetime_immutable` становится `DateTimeImmutable`. На запись и в сравнения DBAL получает имя типа и выполняет преобразование один раз. Для `IN` каждый элемент связывается с типом поля. Пользовательские типы регистрируются в DBAL; второй реестр codecs не создаётся. Типы, требующие специальных SQL-обёрток, в первый контракт не входят.

Для загрузки связей ключи сохраняются из **преобразованных строк**, до `hydrate`. Loader не читает `$model->id` или `$model->category_id`. Типы связанных ключей должны быть совместимы. Для строковых ключей FK и соответствующий PK должны использовать одинаковые правила сравнения (collation). Resolver репозитория связи вызывается отложенно, что допускает циклическую конфигурацию через DI; отдельный registry не нужен.

## Публичное чтение

У `Repository<T>` три точки входа в чтение:

```php
read(): ReadQuery;                         // настройки по умолчанию
withLocale(string $locale, ?string $fallback = null): ReadQuery;
with(array $relations): ReadQuery;         // list<string|Load>
```

Последние две — короткие формы `read()->withLocale(...)` и `read()->with(...)`. Обычное чтение выглядит как `$products->read()->find($id)`. Бизнес-методы приложения могут оборачивать этот API; наследоваться от `BaseRepository` больше не требуется.

Контракт `ReadQuery<T>`:

```php
withLocale(string $locale, ?string $fallback = null): self;
with(array $relations): self;              // list<string|Load>
withVisibility(Visibility $visibility): self;

find(int|string $id): ?object;             // T|null
findOneBy(array|Filter $criteria, array $orderBy = []): ?object;
findBy(
    array|Filter $criteria = [],
    array $orderBy = [],
    ?int $limit = null,
    int $offset = 0,
): array;                                 // list<T>
exists(array|Filter $criteria = []): bool;
count(array|Filter $criteria = []): int;
rows(
    array $columns,
    array|Filter $criteria = [],
    array $orderBy = [],
    ?int $limit = null,
    int $offset = 0,
): array;                                 // list<array<string, mixed>>
iterateById(array|Filter $criteria = [], int $batchSize = 500): iterable;
```

Каждый `with*` возвращает новый объект. Terminal-методы не сбрасывают настройки: запрос можно повторять и независимо разветвлять. `with()` заменяет весь список загрузок, `with([])` очищает его. DBAL builder никогда не хранится как разделяемое изменяемое состояние.

`find` и `findOneBy` ограничивают SQL одной строкой. `exists` запрашивает константу с LIMIT 1. `count` считает корневые записи; загрузки `with()` на эти две операции не влияют. Ни один из этих методов не делает дополнительный COUNT для пагинации.

`rows` возвращает только запрошенные, преобразованные по DBAL типам колонки. Он не создаёт частично заполненные модели. Непустой `with()` для `rows` вызывает ошибку, а не молча игнорируется; можно использовать отдельную ветку запроса с `with([])`.

Без `orderBy` порядок не определён. Для стабильной страницы приложение добавляет уникальный ключ в конец сортировки. Отрицательные limit/offset и неизвестные направления сортировки отклоняются; `limit: 0` возвращает пустой список. Коллекции `findBy()` материализуются полностью в пределах указанного limit.

## Фильтры без второго QueryBuilder

Обычный массив — AND по полям:

```php
['status' => 'active', 'price >=' => '50.00', 'id IN' => [1, 2, 3]]
```

Поддерживаются операторы `=`, `!=`, `>`, `>=`, `<`, `<=`, `IN`, `NOT IN`, `LIKE`, `NOT LIKE`; отсутствие суффикса означает `=`. `= null` и `!= null` превращаются в IS NULL и IS NOT NULL. NULL с прочими операторами, NULL внутри IN и массив без явного IN отклоняются. `IN []` означает false, `NOT IN []` — true. LIKE использует SQL wildcard-семантику; регистр, collation и порядок NULL зависят от БД.

Четыре конструктора `Filter` нужны для группировки и отношений:

```php
Filter::all(array|Filter ...$parts): Filter;
Filter::any(array|Filter ...$parts): Filter;
Filter::not(array|Filter $part): Filter;
Filter::related(
    string $relation,
    array|Filter $criteria = [],
    Visibility $visibility = Visibility::Active,
): Filter;
```

`all` и `any` требуют хотя бы один аргумент. Пустой массив означает true. Логические группы сохраняются буквально; простые константы нормализуются. NOT следует SQL three-valued logic: например, `NOT(price > 10)` не включает NULL-цены автоматически.

`related('tags', ['color' => 'red', 'size' => 'large'])` означает **одну** связанную строку с обоими признаками и компилируется в EXISTS. Два отдельных `related('tags', ...)` в `all` допускают две разные строки. `not(related(...))` — NOT EXISTS. OR внутри related остаётся OR. Корневые записи не размножаются от совпавших связей.

Имена `tags.slug`, SQL-фрагменты, скрытые scope-ключи и неизвестные поля не принимаются. Вложенные связи выражаются вложенными `related`, а не неоднозначным разбором точки. У каждого EXISTS собственные alias и параметры. Сортировка по коллекции не поддерживается: её агрегат и JOIN задаются через DBAL.

Произвольный корневой фильтр с большим IN не разбивается на несколько запросов: это изменило бы семантику OR, сортировки и limit. Превышение бюджета параметров даёт явную ошибку; иной способ передать большой набор задаётся через DBAL. Автоматическое разбиение относится только к внутренним пакетам loader и bulk insert.

## Переводы

`Translations` задаёт `table`, `foreignKey`, `columns`, `defaultLocale` и `localeColumn = 'locale'`. В первом релизе переводимые поля — nullable string/text; их имена не пересекаются с основными колонками. Нужен UNIQUE `(foreignKey, localeColumn)` и внешний ключ на основную таблицу. Для переносимого PATCH upsert эта пара должна быть единственным возможным конфликтом уникальности при вставке переводимых данных; дополнительные UNIQUE по переводимым полям требуют отдельной операции приложения. Это ограничение учитывает поведение [MySQL ON DUPLICATE KEY](https://dev.mysql.com/doc/refman/8.4/en/insert-on-duplicate.html).

UNIQUE должен обеспечивать одну строку на владельца и locale с теми же правилами сравнения ID, что и PK владельца. Например, для строкового PK с `COLLATE NOCASE` в SQLite колонка translation FK тоже требует `COLLATE NOCASE`: бинарный UNIQUE позволил бы два перевода для `Owner` и `owner`, хотя это один владелец. Согласованные типы, collation и ограничения задаются миграциями приложения, без автоматической интроспекции схемы в репозитории.

Служебные `foreignKey` и `localeColumn` не входят в переводимые `columns` и недоступны в patch.

Обычное чтение использует `defaultLocale` без fallback. `withLocale('uk', fallback: 'en')` явно выбирает пару. Неявного глобального языка, цепочки fallback и fallback к колонке основной таблицы нет. Пустая locale отклоняется; совпадающие primary/fallback сворачиваются в один язык.

| Значение для `uk` | Значение для `en` | Результат с fallback `en` |
|---|---|---|
| Нет строки | `"Chair"` | `"Chair"` |
| NULL | `"Chair"` | `"Chair"` |
| `""` | `"Chair"` | `""` |
| `"0"` | `"Chair"` | `"0"` |
| `"Стілець"` | `"Chair"` | `"Стілець"` |
| NULL | Нет строки | NULL |

Таким образом, fallback выражается `COALESCE(primary.field, fallback.field)`. Для каждого поля выбор независим. Без fallback отсутствующая строка/NULL дают NULL. Mapper должен допускать это значение либо применять явно выбранное приложением правило.

Один `FieldResolver` применяется к проекции, WHERE, ORDER BY и условиям внутри связей. Фильтр по `name` проверяет именно то значение с fallback, которое будет показано. Это намеренное отличие от v3, где fallback выборки и условия могли расходиться.

Эффективная пара языков корневого чтения наследуется связями и relation predicates. Если у корня нет переводов и нет `withLocale()`, у первой переводимой сущности каждой ветви применяется её `defaultLocale`, далее он наследуется. Для одного языка всего графа достаточно задать `withLocale()` у корня, даже если он сам не переводится. Переопределения языка отдельной загрузки в 1.0 нет.

При чтении модели из переводимого репозитория default locale означает JOIN по умолчанию. `rows`, `count` и `exists` добавляют translation JOIN только когда их поля/фильтры этого требуют. Нелокализованные физические строки можно читать напрямую через DBAL; отдельный `withoutLocale()` не нужен.

`TranslationStore` отделяет редактирование от отображения:

```php
get(int|string $id, string $locale): ?array;           // собственные поля, без fallback
save(int|string $id, string $locale, array $patch): void;
delete(int|string $id, string $locale): int;
```

`save` — атомарный upsert **только переданных полей**. Значения patch принимаются как string или NULL. Пропущенное поле не изменяется, NULL включает fallback при чтении, `""` сохраняет пустой перевод. При создании строки неприсланные переводимые поля получают NULL, независимо от DEFAULT колонки. Пустой patch — no-op после проверки ID и locale. Переведённые поля в обычном `insert/update` и критериях записи отклоняются. Операции store используют физический ID, включая soft-deleted владельца; правила админского редактирования задаёт приложение.

Сохранение выполняет один native upsert: `ON CONFLICT` на SQLite/PostgreSQL или `ON DUPLICATE KEY UPDATE` на MySQL. Оно участвует в транзакции переданного DBAL connection; ошибки FK и ограничений не подавляются. Для других платформ непустой `save` вызывает ошибку до выполнения SQL. При отсутствии `Mapping.translations` вызов `Repository::translations()` тоже даёт явную ошибку.

`get` возвращает все объявленные переводимые поля с DBAL conversion. NULL вместо массива означает отсутствие строки; существующая строка со всеми NULL возвращает массив.

## Связи

Определения `BelongsTo`, `HasOne`, `HasMany`, `BelongsToMany` содержат отложенный `repository: Closure(): Repository` и `assign: Closure(entity, related): entity`. `assign` возвращает исходный объект после setter либо новую immutable-модель. Для to-one аргумент — объект или NULL, для to-many — список, включая пустой.

Описания связей Solo Repository находятся в namespace `Solo\Repository\Relations` (во множественном числе). Namespace `Solo\BaseRepository\Relation` относится к прежнему пакету `solophp/base-repository`.

У `BelongsTo` `foreignKey` находится у владельца; у `HasOne/HasMany` — у целевой таблицы. В обоих случаях ссылка ведёт на primary key. У `BelongsToMany` задаются `pivotTable`, `foreignPivotKey`, `relatedPivotKey`; UNIQUE по паре ключей и оба FK обязательны. Pivot с дополнительными атрибутами моделируется отдельным репозиторием.

```php
$query = $query->with([
    'category',
    new Load('tags', criteria: ['enabled' => true], orderBy: ['name' => 'ASC']),
]);
```

`Load(name, criteria = [], orderBy = [], visibility = Visibility::Active)` — настройки конкретной связи. `with(['category.parent'])` означает конечный путь загрузки, а не рекурсивный обход графа. Конфликтующие описания одного пути и неизвестные связи вызывают ошибку. Условие Load ограничивает загружаемую коллекцию; оно **не фильтрует владельцев**. Для отбора владельцев используется `Filter::related`, и его условия не меняют содержимое `with()` автоматически.

`Load` также принимает полный путь, например `Load('category.parent', ...)`. Строки создают структуру пути; явный Load задаёт настройки конкретного ребра. Поэтому `with([new Load('category', visibility: Visibility::All), 'category.parent'])` включает удалённую category, но её parent остаётся Active. Различающиеся явные настройки одного точного пути считаются конфликтом.

Loader выполняет один запрос для to-one/has-many и два для many-to-many: pivot, затем связанные модели. Это бюджет на путь и пакет ключей, а не обещание двух запросов при любом объёме. Большие IN разбиваются с учётом общего бюджета 900 параметров и уже связанных параметров. Translation JOIN не добавляет отдельного round-trip.

Единица пакета many-to-many — владельцы. Target SELECT ограничивается EXISTS по pivot и пакету owner IDs, а не списком всех target IDs. Все targets одного владельца сортируются в одном SQL-запросе; loader обходит этот результат в порядке БД и распределяет по pivot. Конкатенация независимо отсортированных target-чанков или PHP-сортировка вместо collation БД не используются.

Отбор/limit владельцев выполняется до загрузки коллекций. Результаты связываются по typed row keys. Непустой `Load.orderBy` задаёт порядок элементов каждой коллекции; без него порядок не гарантируется. `HasOne` требует уникальности внешнего ключа в целевой таблице; несколько совпадений — ошибка данных. `limit per parent`, lazy loading, cross-connection связи и автоматические каскады исключены из 1.0.

Незагруженные связи остаются в состоянии, заданном mapper. Загруженная to-one без видимой цели присваивается как NULL. Наличие одной PHP-инстанции для одной строки между запросами не гарантируется.

## Soft delete

`softDeleteColumn` должна присутствовать в `Mapping.columns` с nullable datetime-типом. Чтение по умолчанию добавляет IS NULL. `Visibility::All` снимает ограничение, `Visibility::Deleted` добавляет IS NOT NULL; при отсутствии soft delete `Active/All` эквивалентны, а `Deleted` — ошибка конфигурации запроса.

`withVisibility()` меняет только корень. Связи и relation predicates по умолчанию используют `Active`; `Load.visibility` и `Filter::related(..., visibility: ...)` переопределяют это явно. Translation JOIN не имеет собственного soft delete.

Условие видимости добавляется снаружи пользовательской группы: `visible AND (criteria)`. Указание `deleted_at` в критериях не отключает его. Поэтому `not(related('tags', []))` означает отсутствие **видимых** тегов.

`update/updateBy` изменяют только активные строки; изменение колонки soft delete через patch запрещено. `delete/deleteBy` ставят UTC timestamp только активным строкам; повторное удаление возвращает 0 и сохраняет прежний timestamp. Без soft delete эти методы удаляют физически. `restore(id)` очищает timestamp только у удалённой строки и требует настройки soft delete. `purge(id)` физически удаляет строку независимо от видимости. Очистка переводов/pivot при физическом удалении определяется FK и миграциями приложения.

## Запись и транзакции

Контракт записи `Repository<T>`:

```php
insert(array $data): int;                              // affected rows
insertGetId(array $data): int|string;                  // ID, не affected rows
insertMany(array $rows, int $batchSize = 500): int;     // affected rows
update(int|string $id, array $patch): int;
updateBy(array|Filter $criteria, array $patch): int;
delete(int|string $id): int;
deleteBy(array|Filter $criteria): int;
restore(int|string $id): int;
purge(int|string $id): int;
translations(): TranslationStore;
links(string $relation): LinkSet;                      // только BelongsToMany
```

`insert` и `insertGetId` каждый выполняют вставку: вызывать их последовательно для одной записи нельзя. При `generatedId: false` ID обязателен. При `generatedId: true` разрешён переданный ID либо генерация integer identity/sequence. UUID, генерируемые на стороне БД, не входят в этот контракт; UUID приложения передаётся явно.

Явный ID допустим, только если его разрешает схема БД. Пакет не добавляет override для GENERATED ALWAYS и не синхронизирует sequence после ручной вставки.

Для MySQL при `generatedId: true` явный нулевой ID отклоняется: без NO_AUTO_VALUE_ON_ZERO сервер может заменить его новым ключом. Для генерации ключа ID нужно опустить.

`insertGetId` использует переданный ID либо проверенную стратегию платформы и возвращает ключ после преобразования по его типу Mapping. PostgreSQL получает ключ через INSERT RETURNING, MySQL/SQLite — через предусмотренное драйвером получение ID. Возможный служебный обмен с БД для ID не считается перечитыванием модели; обещания «всегда один SQL» здесь нет.

Patch не меняет пропущенные поля; NULL записывает SQL NULL. Primary key изменять нельзя. Пустой patch возвращает 0 без SQL. Для `updateBy/deleteBy` разрешены только основные колонки и логические группы; relation/translation predicates отклоняются, чтобы не обещать переносимый UPDATE/DELETE с JOIN.

Пустой критерий или критерий, свёрнутый простыми правилами к true, для массовой записи отклоняется до применения soft-delete условия. Это защита от пустого фильтра, а не доказательство безопасности любого логического выражения. Намеренное изменение всей таблицы выражается прямо через DBAL.

`insertMany` принимает строки с одним набором полей; порядок ключей неважен. Различающиеся наборы отклоняются до первой записи. Пустой список возвращает 0. Чанки учитывают лимит параметров; вся операция атомарна. Возвращаемый affected count зависит от драйвера: 0 от UPDATE не доказывает отсутствие записи.

Несколько операций объединяются стандартным `$db->transactional(...)` на общем Connection. Одиночный CRUD не создаёт дополнительную транзакцию. Составные `insertMany` и операции `LinkSet` используют транзакцию/вложенный savepoint DBAL и пробрасывают ошибки. Автоматические retries отсутствуют.

`LinkSet` предоставляет `attach(id, ids): void`, `detach(id, ids): void`, `sync(id, ids): void`. Входные IDs нормализуются по типу target PK и дедуплицируются; порядок IDs не сохраняется. Attach уже существующей связи и detach отсутствующей — no-op; `sync` устанавливает точное множество, `sync(id, [])` очищает его. Attach/detach пустого списка ничего не делают.

Синхронизация читает все физические pivot-строки владельца, включая ссылки на скрытые soft-delete цели. Фильтры отображения не превращаются в фильтры записи. Для строковых ID LinkSet дополнительно читает канонические ключи через SQL, чтобы attach/detach/sync учитывали collation БД и разные написания одного ID. Ошибки FK/unique не скрываются. Транзакция гарантирует rollback частичного изменения, но не сериализует конкурентные sync/attach автоматически. Если несколько процессов меняют один набор, приложение согласует блокировку владельца или иную стратегию конкурентной записи в общей транзакции.

## Большие выборки и сложный SQL

`iterateById` выполняет keyset batching: `id > lastId`, `ORDER BY id ASC`, `LIMIT batchSize`. Он требует одного неизменяемого ключа DBAL integer/bigint, не принимает другую сортировку и не использует OFFSET/предварительный COUNT. Размер пакета должен быть положительным.

Фильтры, locale, visibility и includes одинаковы для каждого пакета. Память занята текущими владельцами **и их связанными строками**; неограниченный fan-out может быть большим. Завершённые DBAL results освобождаются, история объектов не хранится. Удержание моделей самим потребителем не ограничивается библиотекой.

Это не серверный cursor и не snapshot при конкурентных изменениях. Для согласованного экспорта приложение выбирает транзакцию/изоляцию своей БД. Для произвольного порядка, составного cursor, сырых потоковых строк и отчётов используется DBAL.

У `ReadQuery` нет `toDbal()` в первом релизе: изменение экспортированного builder создало бы второй контракт для alias, проекций и применения политик. Сложный запрос строится на уже инжектированном Connection и может использовать ту же функцию mapper. Soft delete, переводы, type conversion и связи в таком запросе задаются явно.

## Платформы и ошибки

Проверяемая матрица — PostgreSQL 17 (`pdo_pgsql`), MySQL 8.4 (`pdo_mysql`) и SQLite (`pdo_sqlite`). CI проверяет минимальную ветку DBAL 4.3 и актуальную совместимую версию. MariaDB, SQL Server и прочие платформы не объявляются совместимыми автоматически по наличию DBAL-драйвера; совместимость с более старыми серверными версиями здесь не заявляется.

SQL builder, экранирование, параметры, типы и транзакции делегируются DBAL. Узкие операции, для которых нет нужного общего API, проверяют платформу явно: generated ID и PATCH upsert переводов. Нет ветки «всё неизвестное считаем PostgreSQL» и общего INSERT IGNORE, скрывающего сторонние нарушения ограничений.

Ошибки определения Mapping и неподдерживаемые параметры запроса должны выявляться до SQL насколько это возможно; ленивые связи проверяются при первом разрешении. DBAL-ошибки исполнения сохраняются с причиной. Некорректные результаты mapper/assign — ошибки контракта, а не пропущенные модели.

## Переход с v3

Полная последовательность перехода, изменения схемы и примеры «до/после» находятся в [руководстве по миграции](migration.md). В Solo Repository нет `BaseRepository`, `RepositoryInterface`, старых relation DTO и сервисов v3. Документация v3 остаётся в [репозитории прежнего пакета](https://github.com/SoloPHP/Base-Repository/tree/v3.1.0/docs).

| V3 | Solo Repository |
|---|---|
| Наследование и protected-настройки | `new Repository($db, new Mapping(...))`, композиция по умолчанию |
| Статический метод mapper по имени | Типизированный callable; `Model::fromArray(...)` адаптируется к преобразованным DBAL значениям |
| Изменяемые/одноразовые `withLocale`, `with` | Независимый повторно используемый `ReadQuery` |
| `find/findBy` на репозитории | `read()->find/findBy`, либо сразу `withLocale/with` |
| `create/update` возвращают модель | `insertGetId/update`, затем явное чтение при необходимости |
| Нет locale — нет translation JOIN | Настроенный `defaultLocale` для обычного чтения |
| Пустая строка вызывает fallback | Пустая строка — перевод; только NULL/нет строки вызывают fallback |
| Fallback в выводе отличается от фильтра | Одинаковый resolver для вывода, фильтра и сортировки |
| `relation.field` и scope-ключи в массиве | `Filter::related` и обычные функции, возвращающие критерии |
| Связь через публичное свойство repo и имя setter | Lazy callable репозитория и `assign(entity, related): entity` |
| Неявное поведение `deleted_at` в критериях | `Visibility`; отдельные правила видимости связей |
| Произвольные наборы полей в `insertMany` | Один набор полей на вызов |
| Aggregate/transaction/lock wrappers | DBAL и код приложения |

Автоматический compatibility layer в ядро не включается. До миграции данных нужно решить, какие старые `""` должны стать NULL для сохранения fallback. Обязательны аудит уникальности переводов/pivot/has-one, совпадения типов ключей и ожиданий mapper. Например, mapper, который сам создаёт дату из строки, теперь получает `DateTimeImmutable`; набор доступных колонок определяется Mapping.

## Проверка перед релизом

Интеграционные сценарии проверяют каталог с category/tags, переводы, page limit, атомарную запись и обход по ID. Проверяются независимость locale/includes, same-row/OR/NOT EXISTS, soft delete, проекции, NULL/пустые переводы, typed keys, разбиение пакетов, rollback и `assign`, возвращающий immutable-модель.

Для каталога с category + tags в пределах одного пакета проверяется 4 SELECT: владельцы, category, pivot, tags. Для UPDATE по ID — один UPDATE без SELECT. При переходе keyset на следующую страницу тесты проверяют освобождение объектов завершённого пакета. Производительность в миллисекундах зависит от данных, платформы и схемы и не является обещанием преимущества перед ORM.

Порядок проверки и публикации в `RELEASING.md` включает чистую установку пакета, CI, сборку сайта и миграцию реального потребителя. Подготовка этой ветки не публикует тег автоматически.

## Основа DBAL

Дизайн опирается на существующие API DBAL: [QueryBuilder](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.3/reference/query-builder.html), [параметры и получение данных](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.3/reference/data-retrieval-and-manipulation.html), [типы](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.3/reference/types.html), [транзакции](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.3/reference/transactions.html). При реализации проверяются минимальная поддерживаемая и текущая версии DBAL 4.x.
