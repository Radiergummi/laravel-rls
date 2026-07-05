<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Mockery\Exception\NoMatchingExpectationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Tests\TestCase;

use function assert;

#[TestDox('rls:check Command')]
class RlsCheckCommandTest extends TestCase
{
    /**
     * @throws NoMatchingExpectationException
     */
    #[Test]
    #[TestDox('rls:check passes for a table isolated by a non-tenant key')]
    public function passes_for_a_table_isolated_by_a_non_tenant_key(): void
    {
        // An org_id-scoped table is invisible to a tenant_id-only audit; the agnostic detection
        // must consider it and pass.
        Schema::create('checked_things', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->isolatedBy('org_id');
        });

        try {
            // run() forces execution before finally drops the table; a PendingCommand held in a
            // variable defers to its destructor, which fires after the table is gone (so the audit
            // would see nothing).
            $result = $this->artisan('rls:check');
            assert($result instanceof PendingCommand);
            $result->assertExitCode(0)->run();
        } finally {
            Schema::dropIfExists('checked_things');
        }
    }

    /**
     * @throws NoMatchingExpectationException
     */
    #[Test]
    #[TestDox('rls:check flags a table with row-level security enabled but no policy')]
    public function flags_a_table_with_rls_enabled_but_no_policy(): void
    {
        // No tenant_id column anywhere: the old column-name detection would miss this entirely.
        // RLS is on, but there is no policy, so it is half-configured and must be flagged.
        Schema::create('orphan_rls', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });
        DB::statement('alter table "orphan_rls" enable row level security');

        try {
            // run() forces execution before finally drops the table; a PendingCommand held in a
            // variable defers to its destructor, which fires after the table is gone (so the audit
            // would see nothing).
            $result = $this->artisan('rls:check');
            assert($result instanceof PendingCommand);
            $result
                ->expectsOutputToContain('orphan_rls')
                ->assertExitCode(1)
                ->run();
        } finally {
            Schema::dropIfExists('orphan_rls');
        }
    }
}
