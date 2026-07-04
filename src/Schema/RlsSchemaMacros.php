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
            'scopedBy',
            function (string $column, ?string $dimension = null, string $type = 'uuid'): ScopedByDefinition {
                $table = $this->getTable();
                $dimension ??= $column;
                $owner = config('rls.role_model', 'owner') === 'owner';
                $predicate = sprintf('"%s" = rls.context(\'%s\')::%s', $column, $dimension, $type);

                if ($owner) {
                    $predicate = "rls.bypass() or {$predicate}";
                }

                // One helper for every raw command; keeps the protected-call
                // exemption in a single place and doubles as the addRaw callback
                // handed to ScopedByDefinition.
                $raw = function (string $sql): void {
                    // @phpstan-ignore method.protected (macro closure is bound to Blueprint at runtime, where addCommand is in scope)
                    $this->addCommand('rlsRaw', ['sql' => $sql]);
                };

                $raw(sprintf('alter table "%s" enable row level security', $table));

                if ($owner) {
                    $raw(sprintf('alter table "%s" force row level security', $table));
                }

                $raw(sprintf(
                    'create policy "%s_access" on "%s" as permissive for all using (true) with check (true)',
                    $table,
                    $table,
                ));

                $raw(sprintf(
                    'create policy "%s_%s_isolation" on "%s" as restrictive for all using (%s) with check (%s)',
                    $table,
                    $dimension,
                    $table,
                    $predicate,
                    $predicate,
                ));

                return new ScopedByDefinition(
                    $raw,
                    $table,
                    $column,
                    $dimension,
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
