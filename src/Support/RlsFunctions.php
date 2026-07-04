<?php

namespace Radiergummi\Rls\Support;

use Illuminate\Support\Facades\DB;

class RlsFunctions
{
    /** @return array<int, string> */
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

    public static function install(): void
    {
        foreach (self::statements() as $sql) {
            DB::statement($sql);
        }
    }
}
