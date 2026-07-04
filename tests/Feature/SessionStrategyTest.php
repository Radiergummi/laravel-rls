<?php

namespace Radiergummi\Rls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Radiergummi\Rls\Facades\Rls;
use Radiergummi\Rls\RlsServiceProvider;
use Radiergummi\Rls\Support\RlsFunctions;

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
}
