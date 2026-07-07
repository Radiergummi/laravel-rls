<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\RlsServiceProvider;
use Radiergummi\LaravelRls\Tests\CommittedRlsFixtures;
use Radiergummi\LaravelRls\Tests\WithTestingUtils;
use RuntimeException;

/**
 * Threat category 1 — context leaking across a boundary. Context from one unit
 * of work must never reach the next on the same (pooled or long-lived)
 * connection.
 *
 * The deterministic core is here: under the transaction strategy the scope is a
 * transaction-local GUC, so it cannot outlive its transaction — the property that
 * makes the default safe behind a transaction pooler. The remaining cases need
 * live infrastructure in the path (a real PgBouncer, a queue worker, an Octane
 * worker); they are marked incomplete and cross-referenced to the feature tests
 * that already exercise the happy path.
 *
 * No RefreshDatabase — these tests reason about real transaction boundaries, not
 * savepoints inside a wrapping test transaction.
 *
 * @see docs/MILESTONES.md — Milestone B, threat category 1
 */
#[TestDox('Security: cross-worker context leakage')]
class CrossWorkerLeakageTest extends TestCase
{
    use CommittedRlsFixtures;
    use WithTestingUtils;

    private string $a = '11111111-1111-1111-1111-111111111111';

    #[Test]
    #[TestDox('Transaction-local context does not survive its transaction (pooling safety)')]
    public function context_does_not_survive_a_committed_transaction(): void
    {
        // The wrap transaction sets the scope, runs the query, and commits.
        Rls::isolateTo(
            ['tenant_id' => $this->a],
            fn() => $this->assertSame(2, DB::table('widgets')->count()),
        );

        // On the very same connection the scope is gone: the next client on a
        // shared pooled connection reads no context (the GUC reverts to the empty
        // fail-closed sentinel, which rls.context() reads as NULL).
        $effective = $this->selectSingleValueFromDatabase("select rls.context('tenant_id') as value");

        $this->assertNull($effective, 'The scope survived its transaction — it could bleed to the next client.');
    }

    #[Test]
    #[TestDox('An aborted transaction leaves no context behind on the connection')]
    public function context_does_not_survive_an_aborted_transaction(): void
    {
        try {
            Rls::isolateTo(['tenant_id' => $this->a], function (): void {
                DB::transaction(function (): void {
                    DB::table('widgets')->count();

                    throw new RuntimeException('abort mid-transaction');
                });
            });
        } catch (RuntimeException) {
            // expected
        }

        $effective = $this->selectSingleValueFromDatabase("select rls.context('tenant_id') as value");

        $this->assertNull($effective, 'A rolled-back transaction left its scope on the connection.');
    }

    #[Test]
    #[TestDox('Interleaved transactions through a real PgBouncer never share context')]
    public function pooled_transactions_do_not_share_context(): void
    {
        $this->markTestIncomplete(
            'Needs a live PgBouncer in the path (see PgBouncerTest, gated on :6432). The deterministic '
            . 'guarantee it relies on — transaction-local GUCs dying with their transaction — is covered above.',
        );
    }

    #[Test]
    #[TestDox('A queued job cannot see the context of a previous job on the same worker')]
    public function job_does_not_inherit_previous_job_context(): void
    {
        $this->markTestIncomplete(
            'Needs a live queue worker running two jobs in sequence (see QueuedJobContextTest for the '
            . 'propagation path and LeakCanaryTest for the boundary canary). Also: failed-job retry, '
            . 'batched/chained jobs, daemon vs --once.',
        );
    }

    #[Test]
    #[TestDox('An Octane request N cannot see request N+1 context')]
    public function octane_request_does_not_leak_context(): void
    {
        $this->markTestIncomplete(
            'Needs an Octane worker in the path; the leak canary fires at the request boundary '
            . '(see LeakCanaryTest). No Octane harness is wired into the suite yet.',
        );
    }

    protected function getPackageProviders($app): array
    {
        return [RlsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        config(['database.default' => 'pgsql']);
        config(['database.connections.pgsql' => $this->rlsConnection('rls_app')]);
        config(['database.connections.pgsql_admin' => $this->rlsConnection('rls_bypass')]);
        config(['rls.role_model' => 'owner']);
        config(['rls.admin_connection' => 'pgsql_admin']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->installRlsFunctions(DB::connection());

        DB::statement('drop table if exists widgets cascade');
        DB::statement(
            'create table widgets (id uuid primary key default gen_random_uuid(), tenant_id uuid not null)',
        );
        $this->enableIsolation(DB::connection(), 'widgets', 'widgets', force: true);

        // Seed committed through the BYPASSRLS admin connection (owner is FORCE-bound).
        DB::connection('pgsql_admin')->table('widgets')->insert([
            ['tenant_id' => $this->a],
            ['tenant_id' => $this->a],
        ]);
    }

    protected function tearDown(): void
    {
        // Drop as the owner (rls_app); rls_bypass does not own the table.
        DB::statement('drop table if exists widgets cascade');
        Rls::forget();

        parent::tearDown();
    }
}
