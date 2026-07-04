<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Radiergummi\LaravelRls\Tests\TestCase;

class RestrictedModeDslTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('rls.role_model', 'restricted');
        $app['config']->set('rls.admin_connection', 'pgsql');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('restricted_widgets', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->scopedBy('tenant_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('restricted_widgets');
        parent::tearDown();
    }

    public function test_rls_is_enabled_but_not_forced(): void
    {
        $row = DB::selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class where relname = ?',
            ['restricted_widgets'],
        );
        $this->assertTrue($row->relrowsecurity, 'RLS should be enabled');
        $this->assertFalse($row->relforcerowsecurity, 'FORCE should be off in restricted mode');
    }

    public function test_isolation_predicate_has_no_bypass_clause(): void
    {
        $policy = DB::selectOne(
            'select qual from pg_policies where tablename = ? and policyname = ?',
            ['restricted_widgets', 'restricted_widgets_tenant_id_isolation'],
        );

        $this->assertNotNull($policy);
        $this->assertStringContainsString('rls.context', $policy->qual);
        $this->assertStringNotContainsStringIgnoringCase('bypass', $policy->qual);
    }
}
