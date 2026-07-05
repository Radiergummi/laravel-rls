<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;

class SyncCommandTest extends TestCase
{
    #[Test]
    public function generates_typed_helper_from_the_declared_schema(): void
    {
        Rls::defineContext(fn($c) => $c->uuid('tenant_id'));

        $this->artisan('rls:sync')->assertExitCode(0);

        $exists = DB::selectOne(
            'select 1 as e from pg_proc p join pg_namespace n on n.oid = p.pronamespace '
            . "where n.nspname = 'rls' and p.proname = 'tenant_id'",
        );
        $this->assertNotNull($exists, 'expected rls.tenant_id() to be created');
    }

    #[Test]
    public function is_a_noop_without_a_declared_schema(): void
    {
        $this
            ->artisan('rls:sync')
            ->expectsOutputToContain('No context schema')
            ->assertExitCode(0);
    }
}
