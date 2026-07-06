<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

use function php_uname;

use const PHP_VERSION;

final class BenchmarkEnvironment
{
    /**
     * @return array{
     *     pg_version: string,
     *     php_version: string,
     *     uname: string,
     *     emulate_prepares: bool,
     *     pgbouncer: bool,
     *     git_commit: string,
     *     generated_at: string
     * }
     */
    public static function describe(
        string $pgVersion,
        string $gitCommit,
        string $generatedAt,
        bool $pgbouncer,
        bool $emulatePrepares,
    ): array {
        return [
            'pg_version' => $pgVersion,
            'php_version' => PHP_VERSION,
            'uname' => php_uname(),
            'emulate_prepares' => $emulatePrepares,
            'pgbouncer' => $pgbouncer,
            'git_commit' => $gitCommit,
            'generated_at' => $generatedAt,
        ];
    }
}
