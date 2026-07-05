<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Radiergummi\LaravelRls\Tests\TestCase;

class PolicyDslTest extends TestCase
{
    #[Test]
    public function rls_is_enabled_and_forced(): void
    {
        $row = DB::selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class where relname = ?',
            ['widgets'],
        );
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function creates_permissive_base_and_restrictive_isolation_policies(): void
    {
        $policies = collect(
            DB::select(
                'select policyname, permissive from pg_policies where tablename = ? order by policyname',
                ['widgets'],
            ),
        )->keyBy('policyname');

        $this->assertTrue($policies->has('widgets_access'));
        $this->assertTrue($policies->has('widgets_tenant_id_isolation'));
        $this->assertSame('PERMISSIVE', $policies['widgets_access']->permissive);
        $this->assertSame('RESTRICTIVE', $policies['widgets_tenant_id_isolation']->permissive);
    }

    #[Test]
    public function owner_mode_isolation_predicate_includes_the_bypass_clause(): void
    {
        $policy = DB::selectOne(
            'select qual from pg_policies where tablename = ? and policyname = ?',
            ['widgets', 'widgets_tenant_id_isolation'],
        );

        $this->assertNotNull($policy);
        $this->assertStringContainsString('bypass', $policy->qual);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->scopedBy('tenant_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('widgets');
        parent::tearDown();
    }
}
