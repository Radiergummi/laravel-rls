<?php

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\RlsServiceProvider;
use Radiergummi\LaravelRls\Support\RlsFunctions;

/**
 * Session strategy sets a session-level GUC that persists across bare queries
 * without wrapping each in a transaction. Runs without RefreshDatabase so
 * queries execute at transaction level 0.
 */
class SessionStrategyTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [RlsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'rls_test',
            'username' => 'rls_app',
            'password' => 'secret',
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
        $app['config']->set('rls.strategy', 'session');
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RlsFunctions::statements() as $sql) {
            DB::statement($sql);
        }
    }

    protected function tearDown(): void
    {
        // Session GUC persists on the connection; reset it between tests.
        DB::statement("select set_config('app.tenant_id', '', false)");
        Rls::forget();
        parent::tearDown();
    }

    public function test_session_context_persists_across_bare_queries_without_a_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        Rls::actingAs(['tenant_id' => 'session-tenant']);

        // Two separate bare queries (no transaction) both see the session GUC.
        $this->assertSame('session-tenant', DB::selectOne("select rls.context('tenant_id') as v")->v);
        $this->assertSame('session-tenant', DB::selectOne("select rls.context('tenant_id') as v")->v);
        $this->assertSame(0, DB::transactionLevel(), 'no per-query transaction was opened');
    }

    public function test_reset_session_context_clears_the_session_guc(): void
    {
        Rls::actingAs(['tenant_id' => 'to-be-cleared']);
        $this->assertSame('to-be-cleared', DB::selectOne("select rls.context('tenant_id') as v")->v);

        DB::connection()->resetSessionContext();

        $this->assertNull(DB::selectOne("select rls.context('tenant_id') as v")->v);
    }

    public function test_reconnect_reestablishes_session_context(): void
    {
        Rls::actingAs(['tenant_id' => 'survives-reconnect']);
        $this->assertSame('survives-reconnect', DB::selectOne("select rls.context('tenant_id') as v")->v);

        // A fresh backend would have no session GUC; re-establishment restores it.
        DB::connection()->reconnect();

        $this->assertSame('survives-reconnect', DB::selectOne("select rls.context('tenant_id') as v")->v);
    }

    public function test_worker_boundary_flushes_a_session_guc_left_by_a_prior_request(): void
    {
        // A prior request sets context: the session GUC is set and its keys are
        // tracked on the (persistent) connection.
        Rls::actingAs(['tenant_id' => 'req-a-tenant']);
        $this->assertSame('req-a-tenant', DB::selectOne("select rls.context('tenant_id') as v")->v);

        // Simulate an Octane/worker scope reset: the scoped context stack is
        // discarded, but the persistent connection keeps its session GUC — so
        // the leak canary sees an empty stack and cannot catch this.
        app(\Illuminate\Log\Context\Repository::class)->forget('rls');
        $this->assertFalse(Rls::hasContext());
        $this->assertSame(
            'req-a-tenant',
            DB::selectOne("select rls.context('tenant_id') as v")->v,
            'the GUC is still leaked on the connection',
        );

        // The boundary flush clears it before the next request/job runs.
        event(new \Illuminate\Queue\Events\Looping('pgsql', 'default'));

        $this->assertNull(DB::selectOne("select rls.context('tenant_id') as v")->v);
    }
}
