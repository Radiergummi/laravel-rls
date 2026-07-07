<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Threat category 1 (the infrastructure-dependent cases) — context leaking
 * across long-lived-worker and pooling boundaries. Context from unit A must
 * never reach unit B. Needs PgBouncer / a queue worker / Octane in the path, so
 * these are stubbed until that harness is wired in.
 *
 * When implementing: switch the base to SecurityTestCase and gate each test on
 * the relevant infrastructure (see PgBouncerTest / QueuedJobContextTest for the
 * existing patterns).
 *
 * @see docs/MILESTONES.md — Milestone B, threat category 1
 */
#[TestDox('Security: cross-worker context leakage (TODO)')]
class CrossWorkerLeakageTest extends TestCase
{
    #[Test]
    #[TestDox('Interleaved pooled transactions never share context (A must not reach B)')]
    public function pooled_transactions_do_not_share_context(): void
    {
        $this->markTestIncomplete('Milestone B §1: interleaved transactions under PgBouncer transaction pooling.');
    }

    #[Test]
    #[TestDox('A rolled-back / aborted transaction does not carry context to the next')]
    public function aborted_transaction_does_not_leak_context(): void
    {
        $this->markTestIncomplete('Milestone B §1: ROLLBACK, SAVEPOINT/nested, deadlock-retry, aborted transactions.');
    }

    #[Test]
    #[TestDox('A queued job cannot see the context of a previous job on the same worker')]
    public function job_does_not_inherit_previous_job_context(): void
    {
        $this->markTestIncomplete('Milestone B §1: job A invisible to job B; failed-job retry; batched/chained; daemon vs --once.');
    }

    #[Test]
    #[TestDox('An Octane request N cannot see request N+1 context')]
    public function octane_request_does_not_leak_context(): void
    {
        $this->markTestIncomplete('Milestone B §1: Octane long-lived worker request isolation.');
    }
}
