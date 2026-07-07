<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Threat category 7 — migration / DDL hazards.
 *
 * Under owner+FORCE the app role is itself subject to the policy, so a data
 * migration that forgets to establish context does not error — it silently
 * touches zero rows. And enabling isolation on a table that already holds data
 * must scope the existing rows without losing them. Both are characterized here
 * so the sharp edges are visible.
 *
 * @see docs/MILESTONES.md — Milestone B, threat category 7
 */
#[TestDox('Security: migration / DDL hazards')]
class MigrationDdlTest extends SecurityTestCase
{
    #[Test]
    #[TestDox('A data change with no context silently touches zero rows under owner+FORCE')]
    public function data_change_without_context_silently_touches_zero_rows(): void
    {
        // A migration that forgets to set context: no error, just nothing changed.
        $affected = DB::update("update documents set title = 'migrated'");

        $this->assertSame(0, $affected, 'The unscoped data change was not the expected silent no-op.');

        // The rows are untouched — the data change fell through the policy, it did
        // not partially apply.
        $this->isolateTo(['tenant_id' => $this->tenantA->id], function (): void {
            $this->assertSame(0, DB::table('documents')->where('title', 'migrated')->count());
        });
    }

    #[Test]
    #[TestDox('The same data change with context in scope touches exactly the scoped rows')]
    public function data_change_with_context_touches_the_scoped_rows(): void
    {
        $affected = $this->isolateTo(
            ['tenant_id' => $this->tenantA->id],
            fn() => DB::update("update documents set title = 'migrated'"),
        );

        $this->assertSame(self::COUNT_A, $affected);
    }

    #[Test]
    #[TestDox('Enabling isolation on a populated table scopes the existing rows without losing them')]
    public function enabling_isolation_on_a_populated_table_scopes_existing_rows(): void
    {
        // A legacy table with two tenants' rows already in it, no RLS yet.
        Schema::create('legacy', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
        });

        DB::table('legacy')->insert([
            ['tenant_id' => $this->tenantA->id],
            ['tenant_id' => $this->tenantA->id],
            ['tenant_id' => $this->tenantB->id],
        ]);

        // Enable isolation after the fact, the way a follow-up migration would.
        Schema::table('legacy', static function (Blueprint $table): void {
            $table->isolatedBy('tenant_id');
        });

        try {
            // Owner+FORCE now confines even the owner: no context sees nothing...
            $this->assertSame(0, DB::table('legacy')->count());

            // ...but the data is not lost — each tenant sees its own rows.
            $this->isolateTo(
                ['tenant_id' => $this->tenantA->id],
                fn() => $this->assertSame(2, DB::table('legacy')->count()),
            );
            $this->isolateTo(
                ['tenant_id' => $this->tenantB->id],
                fn() => $this->assertSame(1, DB::table('legacy')->count()),
            );
        } finally {
            Schema::dropIfExists('legacy');
        }
    }
}
