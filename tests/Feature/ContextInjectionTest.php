<?php

namespace Radiergummi\Rls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Radiergummi\Rls\Facades\Rls;
use Radiergummi\Rls\Tests\TestCase;

class ContextInjectionTest extends TestCase
{
    public function test_context_reaches_db_within_refresh_database_transaction(): void
    {
        // RefreshDatabase already opened a transaction before this body ran.
        Rls::actingAs(['tenant_id' => 'abc']);
        $this->assertSame('abc', DB::selectOne("select rls.context('tenant_id') as v")->v);
    }

    public function test_scoped_context_applies_then_clears(): void
    {
        Rls::actingAs(['tenant_id' => 'xyz'], function () {
            $this->assertSame('xyz', DB::selectOne("select rls.context('tenant_id') as v")->v);
        });
        $this->assertNull(DB::selectOne("select rls.context('tenant_id') as v")->v);
    }

    public function test_bypass_scope_sets_bypass_guc(): void
    {
        Rls::withoutRls('seeding', function () {
            $this->assertTrue(DB::selectOne('select rls.bypass() as v')->v);
        });
        $this->assertFalse(DB::selectOne('select rls.bypass() as v')->v);
    }
}
