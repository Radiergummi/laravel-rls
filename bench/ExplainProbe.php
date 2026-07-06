<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

use Illuminate\Database\Connection;

use function is_array;
use function is_string;
use function json_decode;
use function reset;
use function str_contains;

use const JSON_THROW_ON_ERROR;

final class ExplainProbe
{
    /**
     * Run a live EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) and parse the plan.
     *
     * @param array<int, mixed> $bindings
     *
     * @return array{scan_type:string,parallel:bool,exec_ms:float}
     */
    public static function probe(Connection $connection, string $sql, array $bindings): array
    {
        $rows = $connection->select(
            'explain (analyze, buffers, format json) ' . $sql,
            $bindings,
        );

        $first = (array) $rows[0];
        $json = reset($first); // the single "QUERY PLAN" column, whatever its key case
        $decoded = is_string($json) ? json_decode($json, true, flags: JSON_THROW_ON_ERROR) : $json;

        /** @var array{Plan: array<string,mixed>, 'Execution Time'?: float} $element */
        $element = $decoded[0];

        return self::parse($element);
    }

    /**
     * @param array{Plan: array<string,mixed>, 'Execution Time'?: float} $explain
     *
     * @return array{scan_type:string,parallel:bool,exec_ms:float}
     */
    public static function parse(array $explain): array
    {
        $plan = $explain['Plan'];

        return [
            'scan_type' => self::firstScan($plan),
            'parallel' => self::hasParallel($plan),
            'exec_ms' => (float) ($explain['Execution Time'] ?? 0.0),
        ];
    }

    /**
     * First node type containing "Scan", walking top-down.
     *
     * @param array<string,mixed> $node
     */
    private static function firstScan(array $node): string
    {
        $type = is_string($node['Node Type'] ?? null) ? $node['Node Type'] : 'Unknown';

        if (str_contains($type, 'Scan')) {
            return $type;
        }

        foreach (self::children($node) as $child) {
            $found = self::firstScan($child);

            if ($found !== 'Unknown') {
                return $found;
            }
        }

        return 'Unknown';
    }

    /**
     * @param array<string,mixed> $node
     */
    private static function hasParallel(array $node): bool
    {
        $type = is_string($node['Node Type'] ?? null) ? $node['Node Type'] : '';

        if (($node['Parallel Aware'] ?? false) === true || str_contains($type, 'Gather')) {
            return true;
        }

        foreach (self::children($node) as $child) {
            if (self::hasParallel($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $node
     *
     * @return list<array<string,mixed>>
     */
    private static function children(array $node): array
    {
        $plans = $node['Plans'] ?? [];

        return is_array($plans)
            ? array_values(array_filter($plans, is_array(...)))
            : [];
    }
}
