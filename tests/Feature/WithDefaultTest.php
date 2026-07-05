<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;

class WithDefaultTest extends TestCase
{
    #[Test]
    public function scoping_column_default_references_the_context(): void
    {
        $default = DB::selectOne(
            'select column_default from information_schema.columns '
            . "where table_name = 'gadgets' and column_name = 'tenant_id'",
        )->column_default;

        $this->assertNotNull($default, 'expected a column default on the scoping column');
        $this->assertStringContainsString('rls.context', $default);
    }

    #[Test]
    public function insert_without_tenant_id_fills_it_from_the_context(): void
    {
        $tenant = '33333333-3333-3333-3333-333333333333';

        Rls::actingAs(['tenant_id' => $tenant], function () use ($tenant) {
            $id = '44444444-4444-4444-4444-444444444444';
            DB::table('gadgets')->insert([
                'id' => $id,
                'name' => 'no explicit tenant',
            ]);

            $row = DB::table('gadgets')->where('id', $id)->first();

            $this->assertSame($tenant, $row?->tenant_id);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gadgets', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->isolatedBy('tenant_id')->withDefault();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('gadgets');
        parent::tearDown();
    }
}
