<?php

namespace Radiergummi\LaravelRls\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;

class RlsSchemaMacros
{
    public static function register(): void
    {
        Blueprint::macro('enableRowLevelSecurity', function (): void {
            /** @var Blueprint $this */
            $table = $this->getTable();
            $this->addCommand('rlsRaw', ['sql' => "alter table \"{$table}\" enable row level security"]);
        });

        Blueprint::macro('forceRowLevelSecurity', function (): void {
            /** @var Blueprint $this */
            $table = $this->getTable();
            $this->addCommand('rlsRaw', ['sql' => "alter table \"{$table}\" force row level security"]);
        });

        Blueprint::macro('scopedBy', function (string $column, ?string $dimension = null, string $type = 'uuid'): void {
            /** @var Blueprint $this */
            $table = $this->getTable();
            $dimension ??= $column;
            $owner = config('rls.role_model', 'owner') === 'owner';

            $predicate = sprintf('"%s" = rls.context(\'%s\')::%s', $column, $dimension, $type);

            if ($owner) {
                $predicate = "rls.bypass() or {$predicate}";
            }

            $this->addCommand('rlsRaw', ['sql' => "alter table \"{$table}\" enable row level security"]);

            if ($owner) {
                $this->addCommand('rlsRaw', ['sql' => "alter table \"{$table}\" force row level security"]);
            }

            $this->addCommand('rlsRaw', ['sql' =>
                "create policy \"{$table}_access\" on \"{$table}\" as permissive for all using (true) with check (true)",
            ]);

            $this->addCommand('rlsRaw', ['sql' =>
                "create policy \"{$table}_{$dimension}_isolation\" on \"{$table}\" " .
                "as restrictive for all using ({$predicate}) with check ({$predicate})",
            ]);
        });

        PostgresGrammar::macro('compileRlsRaw', function ($blueprint, $command) {
            return $command->sql;
        });
    }
}
