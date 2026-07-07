<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Context\ContextSchema;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Testing\InteractsWithRls;
use Radiergummi\LaravelRls\Tests\TestCase;
use RuntimeException;

#[TestDox('tenantIsolated() earned sugar macro')]
class TenantIsolatedMacroTest extends TestCase
{
    use InteractsWithRls;

    #[Test]
    #[TestDox('tenantIsolated() isolates the table by the declared tenant_id dimension')]
    public function tenant_isolated_isolates_by_the_declared_dimension(): void
    {
        Rls::defineContext(static fn(ContextSchema $context) => $context->uuid('tenant_id'));

        Schema::create('widgets', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->tenantIsolated();
        });

        $this->assertTableIsolated('widgets');

        $a = (string) Str::uuid();
        $b = (string) Str::uuid();

        $this->isolateTo(
            ['tenant_id' => $a],
            fn() => DB::table('widgets')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $a]),
        );

        // The row seeded under tenant A is confined to A's scope.
        $this->isolateTo(['tenant_id' => $a], fn() => $this->assertSame(1, DB::table('widgets')->count()));
        $this->isolateTo(['tenant_id' => $b], fn() => $this->assertSame(0, DB::table('widgets')->count()));
    }

    #[Test]
    #[TestDox('tenantIsolated() without a declared tenant_id dimension fails loudly')]
    public function tenant_isolated_without_declaration_throws(): void
    {
        $this->expectException(RuntimeException::class);

        Schema::create('widgets', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->tenantIsolated();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('widgets');
        parent::tearDown();
    }
}
