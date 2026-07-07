<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Threat category 4 — policy correctness under compounding conditions:
 * compound isolation keys with a partial context, and cross-tenant constraints
 * acting as existence oracles.
 *
 * The RESTRICTIVE isolation policies AND together, so *every* key must match for
 * a row to be visible or writable; a partially-set context fails closed. Unique
 * constraints, by contrast, are enforced across all rows regardless of RLS — a
 * globally-unique column therefore leaks another tenant's existence, which is why
 * a tenant key must be part of the constraint.
 *
 * @see docs/MILESTONES.md — Milestone B, threat category 4
 */
#[TestDox('Security: policy compounding')]
class PolicyCompoundingTest extends SecurityTestCase
{
    private const ORG = '99999999-9999-9999-9999-999999999999';

    #[Test]
    #[TestDox('A fully-matched compound context sees only rows matching every key')]
    public function full_compound_context_confines_to_all_keys(): void
    {
        $this->inRegion(1, fn() => $this->assertSame(2, DB::table('reports')->count()));
        $this->inRegion(2, fn() => $this->assertSame(1, DB::table('reports')->count()));
    }

    #[Test]
    #[TestDox('A partial compound context (one key missing) fails closed with zero rows')]
    public function partial_compound_context_fails_closed(): void
    {
        // org_id set, region_id absent: the region isolation policy resolves to
        // `region_id = NULL`, which the compound AND turns into zero rows.
        $this->isolateTo(
            ['org_id' => self::ORG],
            fn() => $this->assertSame(0, DB::table('reports')->count()),
        );
    }

    #[Test]
    #[TestDox('A compound WITH CHECK rejects a write that violates any single key')]
    public function compound_with_check_rejects_a_partial_match_write(): void
    {
        $this->isolateTo(['org_id' => self::ORG, 'region_id' => 1], function (): void {
            try {
                // Same org, wrong region: the region policy's WITH CHECK must reject it.
                DB::transaction(fn() => DB::table('reports')->insert([
                    'org_id' => self::ORG,
                    'region_id' => 2,
                    'title' => 'smuggled',
                ]));

                $this->fail('Expected the compound WITH CHECK to reject the write');
            } catch (QueryException $exception) {
                $this->assertStringContainsStringIgnoringCase('row-level security', $exception->getMessage());
            }
        });
    }

    #[Test]
    #[TestDox('A globally-unique column leaks another tenant\'s existence (known limit)')]
    public function a_global_unique_constraint_is_an_existence_oracle(): void
    {
        $this->isolateTo(
            ['tenant_id' => $this->tenantA->id],
            fn() => DB::table('codes')->insert(['tenant_id' => $this->tenantA->id, 'code' => 'shared']),
        );

        $this->isolateTo(['tenant_id' => $this->tenantB->id], function (): void {
            // B cannot see A's row through the policy...
            $this->assertSame(0, DB::table('codes')->where('code', 'shared')->count());

            // ...but the global unique index still enforces across it, leaking that
            // the value exists. This is why a tenant key belongs in the constraint.
            try {
                DB::transaction(fn() => DB::table('codes')->insert([
                    'tenant_id' => $this->tenantB->id,
                    'code' => 'shared',
                ]));

                $this->fail('Expected a unique-constraint violation to leak existence');
            } catch (QueryException $exception) {
                $this->assertStringContainsStringIgnoringCase('unique', $exception->getMessage());
            }
        });
    }

    #[Test]
    #[TestDox('A tenant-scoped unique constraint does not leak across tenants')]
    public function a_tenant_scoped_unique_constraint_does_not_leak(): void
    {
        $this->isolateTo(
            ['tenant_id' => $this->tenantA->id],
            fn() => DB::table('scoped_codes')->insert(['tenant_id' => $this->tenantA->id, 'code' => 'shared']),
        );

        // B reuses the same code: unique is scoped by (tenant_id, code), so no
        // collision and no oracle.
        $inserted = $this->isolateTo(
            ['tenant_id' => $this->tenantB->id],
            fn() => DB::table('scoped_codes')->insert(['tenant_id' => $this->tenantB->id, 'code' => 'shared']),
        );

        $this->assertTrue($inserted);
    }

    #[Test]
    #[TestDox('withDefault() fills the scope on omit but WITH CHECK still rejects a foreign id')]
    public function with_default_cannot_be_overridden_with_a_foreign_id(): void
    {
        // Omitting the column fills it from context...
        $this->isolateTo(['tenant_id' => $this->tenantA->id], function (): void {
            DB::table('defaulted')->insert(['name' => 'filled']);

            $row = DB::table('defaulted')->where('name', 'filled')->first();
            $this->assertSame($this->tenantA->id, $row?->tenant_id);
        });

        // ...and an explicit foreign id cannot override it past WITH CHECK.
        $this->isolateTo(['tenant_id' => $this->tenantA->id], function (): void {
            try {
                DB::transaction(fn() => DB::table('defaulted')->insert([
                    'tenant_id' => $this->tenantB->id,
                    'name' => 'foreign',
                ]));

                $this->fail('Expected WITH CHECK to reject the foreign id');
            } catch (QueryException $exception) {
                $this->assertStringContainsStringIgnoringCase('row-level security', $exception->getMessage());
            }
        });
    }

    /**
     * @param Closure(): mixed $callback
     */
    private function inRegion(int $region, Closure $callback): mixed
    {
        return $this->isolateTo(['org_id' => self::ORG, 'region_id' => $region], $callback);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Compound isolation: two keys, so the restrictive policies AND together.
        Schema::create('reports', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->integer('region_id');
            $table->string('title')->nullable();
            $table->isolatedBy('org_id');
            $table->isolatedBy('region_id', type: 'integer');
        });

        // Globally-unique code (the existence oracle) vs a tenant-scoped unique.
        Schema::create('codes', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->string('code');
            $table->unique('code');
            $table->isolatedBy('tenant_id');
        });

        Schema::create('scoped_codes', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->string('code');
            $table->unique(['tenant_id', 'code']);
            $table->isolatedBy('tenant_id');
        });

        Schema::create('defaulted', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->string('name')->nullable();
            $table->isolatedBy('tenant_id')->withDefault();
        });

        // Seed the compound table: two rows in region 1, one in region 2, each
        // written under its own fully-matched context.
        $this->inRegion(1, static fn() => DB::table('reports')->insert([
            ['org_id' => self::ORG, 'region_id' => 1, 'title' => 'a'],
            ['org_id' => self::ORG, 'region_id' => 1, 'title' => 'b'],
        ]));
        $this->inRegion(2, static fn() => DB::table('reports')->insert([
            ['org_id' => self::ORG, 'region_id' => 2, 'title' => 'c'],
        ]));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('defaulted');
        Schema::dropIfExists('scoped_codes');
        Schema::dropIfExists('codes');
        Schema::dropIfExists('reports');

        parent::tearDown();
    }
}
