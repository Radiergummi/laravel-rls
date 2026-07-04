<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Radiergummi\LaravelRls\Tests\TestCase;

class RlsCheckCommandTest extends TestCase
{
    public function test_passes_for_a_table_scoped_by_a_non_tenant_dimension(): void
    {
        // An org_id-scoped table is invisible to a tenant_id-only audit; the
        // agnostic detection must consider it and pass.
        Schema::create('checked_things', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->scopedBy('org_id');
        });

        try {
            $this->artisan('rls:check')->assertExitCode(0);
        } finally {
            Schema::dropIfExists('checked_things');
        }
    }

    public function test_flags_a_table_with_rls_enabled_but_no_policy(): void
    {
        // No tenant_id column anywhere: the old column-name detection would miss
        // this entirely. RLS is on but there is no policy, so it is half-
        // configured and must be flagged.
        Schema::create('orphan_rls', function ($table) {
            $table->uuid('id')->primary();
        });
        DB::statement('alter table "orphan_rls" enable row level security');

        try {
            $this->artisan('rls:check')
                ->expectsOutputToContain('orphan_rls')
                ->assertExitCode(1);
        } finally {
            Schema::dropIfExists('orphan_rls');
        }
    }
}
