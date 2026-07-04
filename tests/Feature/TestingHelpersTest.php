<?php

namespace Radiergummi\Rls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Radiergummi\Rls\Testing\InteractsWithRls;
use Radiergummi\Rls\Tests\TestCase;

class TestingHelpersTest extends TestCase
{
    use InteractsWithRls;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gadgets', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->scopedBy('tenant_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('gadgets');
        parent::tearDown();
    }

    public function test_assert_table_protected_passes(): void
    {
        $this->assertTableProtected('gadgets');
    }

    public function test_with_rls_context_scopes_reads(): void
    {
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();

        $this->withoutRls('seed', function () use ($a, $b) {
            DB::table('gadgets')->insert(['id' => Str::uuid(), 'tenant_id' => $a]);
            DB::table('gadgets')->insert(['id' => Str::uuid(), 'tenant_id' => $b]);
        });

        $this->withRlsContext(['tenant_id' => $a], function () {
            $this->assertSame(1, DB::table('gadgets')->count());
        });
    }
}
