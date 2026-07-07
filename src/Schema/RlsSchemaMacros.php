<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Fluent;

/**
 * RLS Schema Macros
 *
 * @mixin Blueprint
 */
class RlsSchemaMacros
{
    public static function register(): void
    {
        Blueprint::macro('enableRowLevelSecurity', function (): void {
            $table = $this->getTable();

            // @phpstan-ignore method.protected (macro closure is bound to Blueprint at runtime, where addCommand is in scope)
            $this->addCommand('rlsRaw', [
                'sql' => sprintf('alter table "%s" enable row level security', $table),
            ]);
        });

        Blueprint::macro('forceRowLevelSecurity', function (): void {
            $table = $this->getTable();

            // @phpstan-ignore method.protected (macro closure is bound to Blueprint at runtime, where addCommand is in scope)
            $this->addCommand('rlsRaw', [
                'sql' => sprintf('alter table "%s" force row level security', $table),
            ]);
        });

        Blueprint::macro(
            'isolatedBy',
            function (string $column, ?string $key = null, string $type = 'uuid'): IsolatedByDefinition {
                $table = $this->getTable();
                $key ??= $column;
                $owner = config('rls.role_model', 'owner') === 'owner';

                // Equality-only predicate: index-friendly (a Bitmap Index Scan on the scoping
                // column). There is no in-band `rls.bypass() OR` clause — the OR would defeat the
                // index and force a Seq Scan on every scoped read. Bypass routes to an admin
                // connection instead, in both role models.
                $predicate = sprintf('"%s" = rls.context(\'%s\')::%s', $column, $key, $type);

                // One helper for every raw command; keeps the protected-call
                // exemption in a single place and doubles as the addRaw callback
                // handed to IsolatedByDefinition.
                $raw = function (string $sql): void {
                    // @phpstan-ignore method.protected (macro closure is bound to Blueprint at runtime, where addCommand is in scope)
                    $this->addCommand('rlsRaw', ['sql' => $sql]);
                };

                $raw(sprintf('alter table "%s" enable row level security', $table));

                if ($owner) {
                    $raw(sprintf('alter table "%s" force row level security', $table));
                }

                // One permissive base policy per table — it is what the restrictive isolation
                // policies AND against. A compound table calls isolatedBy() once per key, all
                // sharing this name, so drop-then-create keeps it idempotent instead of colliding
                // on the second key.
                $raw(sprintf('drop policy if exists "%s_access" on "%s"', $table, $table));
                $raw(sprintf(
                    'create policy "%s_access" on "%s" as permissive for all using (true) with check (true)',
                    $table,
                    $table,
                ));

                $raw(sprintf(
                    'create policy "%s_%s_isolation" on "%s" as restrictive for all using (%s) with check (%s)',
                    $table,
                    $key,
                    $table,
                    $predicate,
                    $predicate,
                ));

                return new IsolatedByDefinition(
                    $raw,
                    $table,
                    $column,
                    $key,
                    $type,
                );
            },
        );

        PostgresGrammar::macro(
            'compileRlsRaw',
            fn(Blueprint $blueprint, Fluent $command) => $command->get('sql'),
        );
    }
}
