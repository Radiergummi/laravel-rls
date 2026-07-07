<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Threat category 8 — covert channels that leak information about another
 * tenant's data without reading its rows. RLS filters row visibility; it does
 * NOT tenant-scope shared objects like sequences or the planner's catalog
 * statistics. These are genuine, inherent limits, characterized here so a user
 * knows not to lean on RLS for them. The constraint-based existence oracle (a
 * global unique index) is characterized in PolicyCompoundingTest.
 *
 * @see docs/MILESTONES.md — Milestone B, threat category 8
 */
#[TestDox('Security: covert channels')]
class CovertChannelTest extends SecurityTestCase
{
    #[Test]
    #[TestDox('A shared sequence leaks another tenant\'s insert activity via gaps (known limit)')]
    public function a_shared_sequence_leaks_cross_tenant_insert_activity(): void
    {
        // A serial/bigserial sequence is a single global object, not tenant-scoped.
        $idA = $this->isolateTo(
            ['tenant_id' => $this->tenantA->id],
            fn() => DB::table('events')->insertGetId(['tenant_id' => $this->tenantA->id]),
        );

        $idB = $this->isolateTo(
            ['tenant_id' => $this->tenantB->id],
            fn() => DB::table('events')->insertGetId(['tenant_id' => $this->tenantB->id]),
        );

        // B's id skipped past A's: the sequence advanced for a row B can't see, so
        // the gap reveals that another tenant inserted. RLS does not close this.
        $this->assertSame(1, $idA);
        $this->assertSame(2, $idB);
    }

    #[Test]
    #[TestDox('Catalog row estimates reflect every tenant, not just the acting one (known limit)')]
    public function catalog_statistics_reflect_all_tenants(): void
    {
        // Planner statistics are computed over the whole heap; RLS does not filter
        // them. A tenant reading pg_class sees the global row count.
        DB::statement('analyze documents');

        $estimate = $this->isolateTo(
            ['tenant_id' => $this->tenantA->id],
            fn() => (int) $this->selectSingleValueFromDatabase(
                "select reltuples::int as value from pg_class where relname = 'documents'",
            ),
        );

        // The acting tenant can see only COUNT_A rows, yet the catalog reports the
        // full total across both tenants.
        $this->assertSame(self::COUNT_A + self::COUNT_B, $estimate);
    }

    #[Test]
    #[TestDox('Timing side channels are a documented limit, not asserted here')]
    public function timing_side_channels_are_out_of_scope(): void
    {
        $this->markTestSkipped(
            'Timing side channels (e.g. a policy predicate that short-circuits) are inherently '
            . 'non-deterministic to assert in a unit test; documented as a known limit rather than tested.',
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('events', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->isolatedBy('tenant_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('events');

        parent::tearDown();
    }
}
