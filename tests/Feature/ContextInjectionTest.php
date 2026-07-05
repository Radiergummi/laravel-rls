<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;

class ContextInjectionTest extends TestCase
{
    #[Test]
    public function context_reaches_db_within_refresh_database_transaction(): void
    {
        // RefreshDatabase already opened a transaction before this body ran.
        Rls::isolateTo(['tenant_id' => 'abc']);
        $this->assertSame('abc', DB::selectOne("select rls.context('tenant_id') as v")->v);
    }

    #[Test]
    public function scoped_context_applies_then_clears(): void
    {
        Rls::isolateTo(['tenant_id' => 'xyz'], function () {
            $this->assertSame('xyz', DB::selectOne("select rls.context('tenant_id') as v")->v);
        });
        $this->assertNull(DB::selectOne("select rls.context('tenant_id') as v")->v);
    }

    #[Test]
    public function bypass_scope_sets_bypass_guc(): void
    {
        Rls::withoutIsolation('seeding', function () {
            $this->assertTrue(DB::selectOne('select rls.bypass() as v')->v);
        });
        $this->assertFalse(DB::selectOne('select rls.bypass() as v')->v);
    }
}
