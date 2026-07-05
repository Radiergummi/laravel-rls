<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Testing\InteractsWithRls;
use Radiergummi\LaravelRls\Tests\Fixtures\Models\Document;
use Radiergummi\LaravelRls\Tests\Fixtures\Models\Tenant;
use Radiergummi\LaravelRls\Tests\TestCase;
use RuntimeException;

#[TestDox('Tenant Isolation')]
class TenantIsolationTest extends TestCase
{
    use InteractsWithRls;

    private Tenant $a;
    private Tenant $b;

    #[Test]
    #[TestDox('assertTableIsolated() asserts that the documents table is isolated')]
    public function table_is_isolated(): void
    {
        $this->assertTableIsolated('documents');
    }

    #[Test]
    #[TestDox('Reads are scoped to the acting tenant')]
    public function reads_are_scoped_to_the_acting_tenant(): void
    {
        $this->isolateTo(
            ['tenant_id' => $this->a->id],
            fn() => $this->assertSame(2, Document::query()->count()),
        );

        $this->isolateTo(
            ['tenant_id' => $this->b->id],
            fn() => $this->assertSame(3, Document::query()->count()),
        );
    }

    #[Test]
    #[TestDox('assertIsolates() confirms no leak between tenants')]
    public function isolation_helper_confirms_no_leak(): void
    {
        $this->assertIsolates(
            Document::class,
            isolatedBy: 'tenant_id',
            acting: $this->a,
            cannotSee: $this->b,
        );
    }

    #[Test]
    #[TestDox('assertRejectsForeignWrite() rejects writes for a foreign tenant')]
    public function cross_tenant_writes_are_rejected(): void
    {
        $this->assertRejectsForeignWrite(
            Document::class,
            isolatedBy: 'tenant_id',
            acting: $this->a,
            foreign: $this->b->id,
        );
    }

    #[Test]
    #[TestDox('Missing isolation context fails closed with zero rows')]
    public function missing_context_is_fail_closed(): void
    {
        // No context set: DB returns zero rows rather than leaking.
        $this->assertSame(0, Document::query()->count());
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('withoutIsolation() bypass sees all tenants')]
    public function bypass_sees_all_tenants(): void
    {
        Rls::withoutIsolation(
            'audit',
            fn() => $this->assertSame(5, Document::query()->count()),
        );
    }

    #[Test]
    #[TestDox('The restrictive isolation policy prevents a permissive feature policy from leaking rows')]
    public function restrictive_policy_prevents_permissive_feature_leak(): void
    {
        // Add a permissive feature policy that would, under a permissive-only design, OR-in other
        // tenants' rows. The RESTRICTIVE isolation policy must still AND-confine reads to the
        // acting tenant.
        DB::statement('create policy documents_public on documents as permissive for select using (true)');

        try {
            $this->isolateTo(['tenant_id' => $this->a->id], fn()
                => $this->assertSame(
                    2,
                    Document::query()->count(),
                    'Restrictive policy failed to confine reads',
                ));
        } finally {
            DB::statement('drop policy documents_public on documents');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // tenants are not RLS-scoped, so create the registry rows directly (and in setUp's body,
        // not a closure, so they read as initialized). Only the scoped documents need the bypass.
        $this->a = Tenant::factory()->createOne();
        $this->b = Tenant::factory()->createOne();

        $this->withoutIsolation('seed', function () {
            Document::factory()->count(2)->create(['tenant_id' => $this->a->id]);
            Document::factory()->count(3)->create(['tenant_id' => $this->b->id]);
        });
    }
}
