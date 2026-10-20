<?php

declare(strict_types=1);

namespace Solo\Repository\Tests\Database;

use Psr\Log\AbstractLogger;
use Stringable;

final class QueryLog extends AbstractLogger
{
    /** @var list<array{sql: string, params: array}> */
    public array $queries = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            $this->queries[] = ['sql' => $context['sql'], 'params' => $context['params'] ?? []];
        }
    }

    public function reset(): void
    {
        $this->queries = [];
    }

    /** @return list<array{sql: string, params: array}> */
    public function selects(): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn(array $query): bool => str_starts_with(ltrim($query['sql']), 'SELECT '),
        ));
    }

    /** @return list<string> */
    public function statements(): array
    {
        return array_column($this->queries, 'sql');
    }
}
