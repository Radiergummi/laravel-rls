<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Tests\TestCase;
use stdClass;

#[TestDox('Restricted Mode DSL')]
class RestrictedModeDslTest extends TestCase
{
    #[Test]
    #[TestDox('isolatedBy() enables row level security but does not force it in restricted mode')]
    public function rls_is_enabled_but_not_forced(): void
    {
        $row = DB::selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class where relname = ?',
            ['restricted_widgets'],
        );
        $this->assertIsObject($row);
        $this->assertInstanceOf(stdClass::class, $row);
        $this->assertTrue($row->relrowsecurity, 'RLS should be enabled');
        $this->assertFalse($row->relforcerowsecurity, 'FORCE should be off in restricted mode');
    }

    #[Test]
    #[TestDox('The isolation predicate has no bypass clause in restricted mode')]
    public function isolation_predicate_has_no_bypass_clause(): void
    {
        $policy = DB::selectOne(
            'select qual from pg_policies where tablename = ? and policyname = ?',
            ['restricted_widgets', 'restricted_widgets_tenant_id_isolation'],
        );

        $this->assertIsObject($policy);
        $this->assertInstanceOf(stdClass::class, $policy);
        $this->assertStringContainsString('rls.context', $policy->qual);
        $this->assertStringNotContainsStringIgnoringCase('bypass', $policy->qual);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        config(['rls.role_model' => 'restricted']);
        config(['rls.admin_connection' => 'pgsql']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('restricted_widgets', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->isolatedBy('tenant_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('restricted_widgets');
        parent::tearDown();
    }
}
