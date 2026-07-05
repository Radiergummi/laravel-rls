<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;
use RuntimeException;
use stdClass;

#[TestDox('With Default')]
class WithDefaultTest extends TestCase
{
    #[Test]
    #[TestDox('withDefault() makes the isolation column default reference the context')]
    public function scoping_column_default_references_the_context(): void
    {
        $defaults = DB::selectOne(
            'select column_default from information_schema.columns '
            . "where table_name = 'gadgets' and column_name = 'tenant_id'",
        );
        $this->assertIsObject($defaults);
        $this->assertInstanceOf(stdClass::class, $defaults);

        $default = $defaults->column_default;

        $this->assertNotNull($default, 'expected a column default on the scoping column');
        $this->assertStringContainsString('rls.context', $default);
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('Inserting without tenant_id fills it from the isolation context')]
    public function insert_without_tenant_id_fills_it_from_the_context(): void
    {
        $tenant = '33333333-3333-3333-3333-333333333333';

        Rls::isolateTo(['tenant_id' => $tenant], function () use ($tenant) {
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

        Schema::create('gadgets', static function (Blueprint $table) {
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
