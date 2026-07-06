<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Testing\InteractsWithRls;
use Radiergummi\LaravelRls\Tests\TestCase;
use RuntimeException;

#[TestDox('Testing Helpers')]
class TestingHelpersTest extends TestCase
{
    use InteractsWithRls;

    #[Test]
    #[TestDox('assertTableIsolated() asserts that a table is isolated')]
    public function assert_table_isolated_passes(): void
    {
        $this->assertTableIsolated('gadgets');
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('isolateTo() scopes reads')]
    public function isolate_to_scopes_reads(): void
    {
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();

        // WITH CHECK permits same-tenant writes, so seed each gadget within its own context.
        $this->isolateTo(
            ['tenant_id' => $a],
            fn() => DB::table('gadgets')->insert(['id' => Str::uuid(), 'tenant_id' => $a]),
        );
        $this->isolateTo(
            ['tenant_id' => $b],
            fn() => DB::table('gadgets')->insert(['id' => Str::uuid(), 'tenant_id' => $b]),
        );

        $this->isolateTo(['tenant_id' => $a], function () {
            $this->assertSame(1, DB::table('gadgets')->count());
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gadgets', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->isolatedBy('tenant_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('gadgets');
        parent::tearDown();
    }
}
