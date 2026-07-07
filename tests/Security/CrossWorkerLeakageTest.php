<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Octane\Events\RequestReceived;
use Orchestra\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\RlsServiceProvider;
use Radiergummi\LaravelRls\Tests\CommittedRlsFixtures;
use Radiergummi\LaravelRls\Tests\WithTestingUtils;
use RuntimeException;
use Throwable;

/**
 * Threat category 1 — context leaking across a boundary. Context from one unit
 * of work must never reach the next on the same (pooled or long-lived)
 * connection.
 *
 * Covered here: the deterministic core (transaction-local scope cannot outlive
 * its transaction — the property that makes the default safe behind a transaction
 * pooler), a row-level check through a real PgBouncer (gated on :6432), and that
 * the Octane request-boundary canary listener is registered. The queue A→B case
 * lives in QueuedJobContextTest (a live `queue:work` daemon: context reaches a
 * job, and one job does not inherit the previous job's context).
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
    #[TestDox('Row isolation holds through a real PgBouncer, and no context fails closed')]
    public function pooled_transactions_do_not_share_context(): void
    {
        try {
            DB::connection('pgsql_bouncer')->getPdo();
        } catch (Throwable $exception) {
            $this->markTestSkipped("PgBouncer not reachable on 127.0.0.1:6432: {$exception->getMessage()}");
        }

        // Each scoped read is its own pooled transaction: the acting tenant sees
        // only its rows through the pooler...
        $scoped = Rls::isolateTo(
            ['tenant_id' => $this->a],
            fn() => DB::connection('pgsql_bouncer')->table('widgets')->count(),
        );
        $this->assertSame(2, $scoped);

        // ...and a context-less read on a shared pooled connection sees none.
        $this->assertSame(0, DB::connection('pgsql_bouncer')->table('widgets')->count());
    }

    #[Test]
    #[TestDox('The Octane request boundary has a leak-canary listener registered')]
    public function octane_request_boundary_has_a_canary_listener(): void
    {
        // No Octane runtime here (swoole/FrankenPHP/RoadRunner), so request N / N+1
        // cannot be driven end to end. The canary that clears context at that
        // boundary is wired on Octane's RequestReceived; assert it is registered.
        // Its clearing behavior is covered by LeakCanaryTest, and the underlying
        // scoped-repository fix by QueuedJobContextTest's daemon test.
        $this->assertTrue(Event::hasListeners(RequestReceived::class));
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
        // Same rls_app role, but through PgBouncer (transaction pooling): a
        // different port, no SSL, and client-side prepares (the pooler cannot
        // carry server-side prepared statements across the pool).
        config(['database.connections.pgsql_bouncer' => [
            ...$this->rlsConnection('rls_app'),
            'port' => 6432,
            'sslmode' => 'disable',
            'options' => [PDO::ATTR_EMULATE_PREPARES => true],
        ]]);
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
