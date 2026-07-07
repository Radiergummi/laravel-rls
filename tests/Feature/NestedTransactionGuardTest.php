<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Exceptions\NestedTenantContext;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;
use Throwable;

#[TestDox('Nested-transaction tenant-change guard')]
class NestedTransactionGuardTest extends TestCase
{
    /**
     * @throws Throwable
     */
    #[Test]
    #[TestDox('switching an isolation key to a different value inside an open transaction throws')]
    public function switching_isolation_key_inside_a_transaction_throws(): void
    {
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();

        $this->expectException(NestedTenantContext::class);

        Rls::isolateTo(['tenant_id' => $a], function () use ($b): void {
            DB::transaction(function () use ($b): void {
                // The transaction opened under tenant A; switching to B mid-transaction is the hazard.
                Rls::isolateTo(['tenant_id' => $b], fn() => null);
            });
        });
    }

    /**
     * @throws Throwable
     */
    #[Test]
    #[TestDox('re-entering the same isolation context inside a transaction is allowed')]
    public function re_entering_the_same_context_inside_a_transaction_is_allowed(): void
    {
        $a = (string) Str::uuid();

        $count = Rls::isolateTo(['tenant_id' => $a], fn() => DB::transaction(
            fn() => Rls::isolateTo(['tenant_id' => $a], fn() => DB::table('things')->count()),
        ));

        $this->assertSame(0, $count);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    #[TestDox('adding a new isolation dimension inside a transaction is allowed')]
    public function adding_a_new_dimension_inside_a_transaction_is_allowed(): void
    {
        $a = (string) Str::uuid();

        $seen = Rls::isolateTo(['tenant_id' => $a], fn() => DB::transaction(function (): mixed {
            // Adds a second, unrelated dimension without changing tenant_id — not a scope switch.
            Rls::set('region', 'eu');

            return Rls::get('tenant_id');
        }));

        $this->assertSame($a, $seen);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    #[TestDox('switching isolation key outside a transaction is allowed (normal wrap flow)')]
    public function switching_isolation_key_outside_a_transaction_is_allowed(): void
    {
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();

        $first = Rls::isolateTo(['tenant_id' => $a], fn() => DB::table('things')->count());
        $second = Rls::isolateTo(['tenant_id' => $b], fn() => DB::table('things')->count());

        $this->assertSame(0, $first);
        $this->assertSame(0, $second);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The guard is opt-in; every case here runs with it enabled.
        $app['config']->set('rls.on_nested_change', 'throw');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('things', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->isolatedBy('tenant_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('things');
        parent::tearDown();
    }
}
