<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Exceptions\MissingIsolationContext;
use Radiergummi\LaravelRls\Tests\Fixtures\Models\Document;

/**
 * Threat category 3 — SQL injection and the raw-SQL boundary. Two distinct
 * layers, and this suite pins where each stands:
 *
 *  1. The DATABASE policy is the real boundary. It confines *every* access path
 *     on the connection — Eloquent, the query builder, and hand-written raw SQL
 *     alike — because the filter lives in Postgres, not in PHP. These tests prove
 *     raw reads and writes stay scoped and fail closed.
 *
 *  2. The PHP fail-loud guard (`on_missing_context = throw`) is a best-effort
 *     convenience, not the boundary. It matches the query-builder's quoted
 *     identifiers (`"documents"`); hand-written raw SQL with an unquoted table
 *     name slips past it — but the database still fails closed, so security is
 *     unaffected. This is the known-fuzzy edge, characterized here rather than
 *     hidden.
 *
 *  3. A `SECURITY DEFINER` function owned by a privileged role runs outside the
 *     caller's scope. That is a documented bypass — a known limit, asserted so it
 *     can never regress into a surprise.
 *
 * @see docs/MILESTONES.md — Milestone B, threat category 3
 */
#[TestDox('Security: raw-SQL boundary')]
class RawSqlBoundaryTest extends SecurityTestCase
{
    #[Test]
    #[TestDox('Raw DB::select on a managed table is confined to the acting tenant')]
    public function raw_select_is_confined_to_the_acting_tenant(): void
    {
        $count = $this->isolateTo(
            ['tenant_id' => $this->tenantA->id],
            fn() => (int) $this->selectSingleValueFromDatabase(
                'select count(*) as value from documents',
            ),
        );

        $this->assertSame(self::COUNT_A, $count);
    }

    #[Test]
    #[TestDox('Raw DB::select with no context fails closed at the database, not open')]
    public function raw_select_without_context_fails_closed(): void
    {
        $count = (int) $this->selectSingleValueFromDatabase(
            'select count(*) as value from documents',
        );

        $this->assertSame(0, $count);
    }

    #[Test]
    #[TestDox('A raw UPDATE cannot reach another tenant\'s rows')]
    public function raw_update_cannot_touch_another_tenant(): void
    {
        $affected = $this->isolateTo(
            ['tenant_id' => $this->tenantA->id],
            fn() => DB::update("update documents set title = 'breach'"),
        );

        // The raw UPDATE only saw the acting tenant's rows.
        $this->assertSame(self::COUNT_A, $affected);

        // ...and none of tenant B's rows were touched.
        $this->isolateTo(['tenant_id' => $this->tenantB->id], function (): void {
            $this->assertSame(
                0,
                Document::query()->where('title', 'breach')->count(),
                'A raw UPDATE reached across the tenant boundary.',
            );
        });
    }

    #[Test]
    #[TestDox('A raw DELETE cannot reach another tenant\'s rows')]
    public function raw_delete_cannot_touch_another_tenant(): void
    {
        $deleted = $this->isolateTo(
            ['tenant_id' => $this->tenantA->id],
            fn() => DB::delete('delete from documents'),
        );

        $this->assertSame(self::COUNT_A, $deleted);

        // Tenant B's rows survive an unscoped-looking raw DELETE.
        $this->isolateTo(['tenant_id' => $this->tenantB->id], function (): void {
            $this->assertSame(self::COUNT_B, Document::query()->count());
        });
    }

    #[Test]
    #[TestDox('Fail-loud mode catches query-builder access to a managed table with no context')]
    public function fail_loud_guard_catches_quoted_managed_table_access(): void
    {
        config(['rls.on_missing_context' => 'throw']);

        $this->expectException(MissingIsolationContext::class);

        // The query builder quotes identifiers ("documents"), which the guard matches.
        DB::select('select * from "documents"');
    }

    #[Test]
    #[TestDox('Fail-loud mode does NOT catch unquoted raw SQL — but the database still fails closed')]
    public function fail_loud_guard_misses_unquoted_raw_sql_yet_db_stays_closed(): void
    {
        // Known limit: the guard keys on the quoted identifier "documents", so an
        // unquoted hand-written statement slips past the PHP-level throw. This is
        // a convenience gap, not a security one — the database policy still
        // returns zero rows.
        config(['rls.on_missing_context' => 'throw']);

        $count = (int) $this->selectSingleValueFromDatabase(
            'select count(*) as value from documents',
        );

        $this->assertSame(0, $count, 'The database policy failed to confine an unguarded raw read.');
    }

    #[Test]
    #[TestDox('A SECURITY DEFINER function owned by a privileged role bypasses isolation (known limit)')]
    public function security_definer_function_bypasses_isolation(): void
    {
        // A function owned by the BYPASSRLS role runs as that role, outside the
        // caller's scope. Create it *as* rls_bypass on the admin connection so it
        // is owned by that role (rls_app cannot re-own to a role it is not a
        // member of); the app session then calls it within its own transaction.
        $admin = DB::connection('pgsql_admin');
        $admin->statement(
            'create function count_all_documents() returns bigint language sql security definer '
            . 'as $$ select count(*) from documents $$',
        );

        try {
            $seen = $this->isolateTo(
                ['tenant_id' => $this->tenantA->id],
                fn() => (int) $this->selectSingleValueFromDatabase(
                    'select count_all_documents() as value',
                ),
            );

            // Documented boundary: the SECURITY DEFINER function saw *every*
            // tenant's rows, not just the acting tenant's. RLS confines rows on a
            // connection; it does not sandbox a function that deliberately runs
            // as another role.
            $this->assertSame(
                self::COUNT_A + self::COUNT_B,
                $seen,
                'SECURITY DEFINER no longer bypasses isolation — the documented boundary changed; update the docs.',
            );
        } finally {
            // Created on a connection outside the RefreshDatabase transaction, so
            // it persists unless dropped explicitly.
            $admin->statement('drop function if exists count_all_documents()');
        }
    }

    #[Test]
    #[TestDox('A CTE over a managed table stays confined to the acting tenant')]
    public function a_cte_over_a_managed_table_stays_confined(): void
    {
        $scoped = $this->isolateTo(
            ['tenant_id' => $this->tenantA->id],
            fn() => (int) $this->selectSingleValueFromDatabase(
                'with scoped as (select * from documents) select count(*) as value from scoped',
            ),
        );

        $this->assertSame(self::COUNT_A, $scoped);
    }

    #[Test]
    #[TestDox('A view over a managed table stays confined (the policy filters by session context)')]
    public function a_view_over_a_managed_table_stays_confined(): void
    {
        // The view is owned by the FORCE-bound owner, but the policy predicate
        // reads the session GUC, so the view sees exactly the caller's scope —
        // it does not become an escape hatch.
        DB::statement('create view visible_documents as select * from documents');

        $scoped = $this->isolateTo(
            ['tenant_id' => $this->tenantA->id],
            fn() => (int) $this->selectSingleValueFromDatabase(
                'select count(*) as value from visible_documents',
            ),
        );
        $this->assertSame(self::COUNT_A, $scoped);

        $this->assertSame(
            0,
            (int) $this->selectSingleValueFromDatabase('select count(*) as value from visible_documents'),
            'The view leaked rows with no context set.',
        );
    }

    #[Test]
    #[TestDox('TRUNCATE is table-level and bypasses row isolation (known limit)')]
    public function truncate_bypasses_row_isolation(): void
    {
        // TRUNCATE is not row-filtered: run under tenant A, it still clears tenant
        // B's rows. Do not rely on RLS to scope a destructive table-level command;
        // gate TRUNCATE with privileges instead.
        $this->isolateTo(
            ['tenant_id' => $this->tenantA->id],
            fn() => DB::statement('truncate documents'),
        );

        $survivingForB = $this->isolateTo(
            ['tenant_id' => $this->tenantB->id],
            fn() => DB::table('documents')->count(),
        );

        $this->assertSame(
            0,
            $survivingForB,
            'TRUNCATE under tenant A was confined — the known TRUNCATE-bypasses-RLS limit no longer holds; update the docs.',
        );
    }
}
