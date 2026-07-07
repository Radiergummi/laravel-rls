<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Threat category 3 — SQL injection and the raw-SQL boundary. RLS confines rows
 * on a connection; it does not sandbox arbitrary SQL. This suite must pin the
 * exact boundary: what the fail-loud guard catches, and where a caller can step
 * outside the policy (SECURITY DEFINER, the admin connection). Genuine limits
 * are documented as known-limits, not hidden.
 *
 * When implementing: switch the base to SecurityTestCase.
 *
 * @see docs/MILESTONES.md — Milestone B, threat category 3
 */
#[TestDox('Security: raw-SQL boundary (TODO)')]
class RawSqlBoundaryTest extends TestCase
{
    #[Test]
    #[TestDox('Raw DB::statement / DB::select / DB::unprepared on a managed table stays confined')]
    public function raw_sql_on_a_managed_table_stays_confined(): void
    {
        $this->markTestIncomplete('Milestone B §3: characterize exactly what the fail-loud guard catches and what leaks.');
    }

    #[Test]
    #[TestDox('A SECURITY DEFINER function is a documented bypass, not silently confined')]
    public function security_definer_boundary_is_pinned(): void
    {
        $this->markTestIncomplete('Milestone B §3: SECURITY DEFINER functions, views, CTEs, subqueries, triggers, COPY, TRUNCATE.');
    }
}
