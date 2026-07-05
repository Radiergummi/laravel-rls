<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckCommand extends Command
{
    protected $signature = 'rls:check';

    protected $description = 'Audit that RLS-managed tables have RLS enabled and at least one policy';

    public function handle(): int
    {
        // Detect managed tables by the artifacts isolatedBy() produces — RLS
        // enabled or an isolation policy — rather than by a hardcoded column
        // name, so any declared isolation key (org_id, region, ...) is audited, not
        // just tenant_id. A table with *no* RLS setup at all has no agnostic
        // signal and is therefore out of scope for this audit.
        $tables = DB::select(
            <<<'SQL'
                select distinct c.relname as name
                from pg_class c
                join pg_namespace n on n.oid = c.relnamespace
                where c.relkind = 'r'
                  and n.nspname = current_schema()
                  and (
                    c.relrowsecurity
                    or exists (
                      select 1 from pg_policies p
                      where p.schemaname = n.nspname and p.tablename = c.relname
                    )
                  )
                order by name
                SQL,
        );

        $violations = [];

        foreach ($tables as $table) {
            $enabled = DB::selectOne(
                'select relrowsecurity from pg_class where relname = ?',
                [$table->name],
            )?->relrowsecurity;

            $policies = DB::selectOne(
                'select count(*) as count from pg_policies where tablename = ?',
                [$table->name],
            )->count;

            if (!$enabled) {
                $violations[] = "{$table->name}: RLS not enabled";
            } elseif ((int) $policies === 0) {
                $violations[] = "{$table->name}: no policy defined";
            }
        }

        if ($violations !== []) {
            foreach ($violations as $violation) {
                $this->error($violation);
            }

            $this->error(count($violations) . ' scoped table(s) are unprotected.');

            return self::FAILURE;
        }

        $this->info(count($tables) . ' scoped table(s) protected.');

        return self::SUCCESS;
    }
}
