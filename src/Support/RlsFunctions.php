<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Support;

use Illuminate\Support\Facades\DB;

class RlsFunctions
{
    public static function install(): void
    {
        foreach (self::statements() as $sql) {
            DB::statement($sql);
        }
    }

    /**
     * @return array<int, string>
     */
    public static function statements(): array
    {
        return [
            'create schema if not exists rls',

            <<<'SQL'
                create or replace function rls.context(key text)
                returns text
                language sql
                stable
                as $$
                    select nullif(current_setting('app.' || key, true), '')
                $$
                SQL,

            <<<'SQL'
                create or replace function rls.bypass()
                returns boolean
                language sql
                stable
                as $$
                    select coalesce(nullif(current_setting('app.bypass', true), ''), 'off')::boolean
                $$
                SQL,
        ];
    }
}
