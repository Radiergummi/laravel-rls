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

#[TestDox('Policy DSL')]
class PolicyDslTest extends TestCase
{
    #[Test]
    #[TestDox('isolatedBy() enables and forces row level security')]
    public function rls_is_enabled_and_forced(): void
    {
        $row = DB::selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class where relname = ?',
            ['widgets'],
        );
        $this->assertIsObject($row);
        $this->assertInstanceOf(stdClass::class, $row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    #[TestDox('isolatedBy() creates a permissive base policy and a restrictive isolation policy')]
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
        $this->assertInstanceOf(stdClass::class, $policies['widgets_access']);
        $this->assertSame('PERMISSIVE', $policies['widgets_access']->permissive);
        $this->assertInstanceOf(stdClass::class, $policies['widgets_tenant_id_isolation']);
        $this->assertSame('RESTRICTIVE', $policies['widgets_tenant_id_isolation']->permissive);
    }

    #[Test]
    #[TestDox('The owner-mode isolation predicate is equality-only, with no bypass clause')]
    public function owner_mode_isolation_predicate_is_equality_only(): void
    {
        $policy = DB::selectOne(
            'select qual from pg_policies where tablename = ? and policyname = ?',
            ['widgets', 'widgets_tenant_id_isolation'],
        );

        $this->assertIsObject($policy);
        $this->assertInstanceOf(stdClass::class, $policy);
        $this->assertIsString($policy->qual);

        // Equality-only: index-friendly. The `rls.bypass() OR` clause is gone — it would defeat the
        // scoping-column index and force a Seq Scan on every read.
        $this->assertStringContainsString('rls.context', $policy->qual);
        $this->assertStringNotContainsStringIgnoringCase('bypass', $policy->qual);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->isolatedBy('tenant_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('widgets');
        parent::tearDown();
    }
}
