<?php

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;

class SyncCommandTest extends TestCase
{
    public function test_generates_typed_helper_from_the_declared_schema(): void
    {
        Rls::defineContext(fn ($c) => $c->uuid('tenant_id'));

        $this->artisan('rls:sync')->assertExitCode(0);

        $exists = DB::selectOne(
            "select 1 as e from pg_proc p join pg_namespace n on n.oid = p.pronamespace " .
            "where n.nspname = 'rls' and p.proname = 'tenant_id'",
        );
        $this->assertNotNull($exists, 'expected rls.tenant_id() to be created');
    }

    public function test_is_a_noop_without_a_declared_schema(): void
    {
        $this->artisan('rls:sync')
            ->expectsOutputToContain('No context schema')
            ->assertExitCode(0);
    }
}
