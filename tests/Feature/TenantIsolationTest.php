<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Testing\InteractsWithRls;
use Radiergummi\LaravelRls\Tests\Models\Document;
use Radiergummi\LaravelRls\Tests\Models\Tenant;
use Radiergummi\LaravelRls\Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use InteractsWithRls;

    private Tenant $a;
    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        // tenants is not RLS-scoped, so create the registry rows directly (and
        // in setUp's body, not a closure, so they read as initialized). Only the
        // scoped documents need the bypass.
        $this->a = Tenant::factory()->createOne();
        $this->b = Tenant::factory()->createOne();

        $this->withoutRls('seed', function () {
            Document::factory()->count(2)->create(['tenant_id' => $this->a->id]);
            Document::factory()->count(3)->create(['tenant_id' => $this->b->id]);
        });
    }

    public function test_table_is_protected(): void
    {
        $this->assertTableProtected('documents');
    }

    public function test_reads_are_scoped_to_the_acting_tenant(): void
    {
        $this->withRlsContext(['tenant_id' => $this->a->id], function () {
            $this->assertSame(2, Document::count());
        });

        $this->withRlsContext(['tenant_id' => $this->b->id], function () {
            $this->assertSame(3, Document::count());
        });
    }

    public function test_isolation_helper_confirms_no_leak(): void
    {
        $this->assertRlsIsolates(Document::class, from: $this->a, cannotSee: $this->b);
    }

    public function test_cross_tenant_writes_are_rejected(): void
    {
        $this->assertCannotWriteAcrossTenants(Document::class, actingAs: $this->a, tenant: $this->b->id);
    }

    public function test_missing_context_is_fail_closed(): void
    {
        // No context set: DB returns zero rows rather than leaking.
        $this->assertSame(0, Document::count());
    }

    public function test_bypass_sees_all_tenants(): void
    {
        Rls::withoutRls('audit', function () {
            $this->assertSame(5, Document::count());
        });
    }

    public function test_restrictive_policy_prevents_permissive_feature_leak(): void
    {
        // Add a permissive feature policy that would, under a permissive-only
        // design, OR-in other tenants' rows. The RESTRICTIVE isolation policy
        // must still AND-confine reads to the acting tenant.
        DB::statement('create policy documents_public on documents as permissive for select using (true)');

        try {
            $this->withRlsContext(['tenant_id' => $this->a->id], function () {
                $this->assertSame(2, Document::count(), 'Restrictive policy failed to confine reads');
            });
        } finally {
            DB::statement('drop policy documents_public on documents');
        }
    }
}
