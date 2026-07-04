<?php

namespace Radiergummi\LaravelRls\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckCommand extends Command
{
    protected $signature = 'rls:check';

    protected $description = 'Audit that tenant-scoped tables have RLS enabled and at least one policy';

    public function handle(): int
    {
        $tables = DB::select(<<<'SQL'
            select distinct c.relname as name
            from pg_class c
            join pg_attribute a on a.attrelid = c.oid
            join pg_namespace n on n.oid = c.relnamespace
            where a.attname = 'tenant_id'
              and c.relkind = 'r'
              and n.nspname = current_schema()
            order by name
        SQL);

        $violations = [];

        foreach ($tables as $table) {
            $enabled = DB::selectOne(
                'select relrowsecurity from pg_class where relname = ?',
                [$table->name],
            )?->relrowsecurity;

            $policies = DB::selectOne(
                'select count(*) as c from pg_policies where tablename = ?',
                [$table->name],
            )->c;

            if (! $enabled) {
                $violations[] = "{$table->name}: RLS not enabled";
            } elseif ((int) $policies === 0) {
                $violations[] = "{$table->name}: no policy defined";
            }
        }

        if ($violations !== []) {
            foreach ($violations as $violation) {
                $this->error($violation);
            }

            $this->error(count($violations) . ' tenant-scoped table(s) are unprotected.');

            return self::FAILURE;
        }

        $this->info(count($tables) . ' tenant-scoped table(s) protected.');

        return self::SUCCESS;
    }
}
