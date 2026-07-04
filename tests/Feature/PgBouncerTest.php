<?php

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\RlsServiceProvider;
use Radiergummi\LaravelRls\Support\RlsFunctions;

/**
 * Proves the transaction strategy against a real PgBouncer in transaction
 * pooling mode — the deployment the design targets. Each transaction may land
 * on a different pooled server connection, so context must be (re)injected at
 * every BEGIN (which the transaction-local GUC mechanism does) and must not
 * leak between transactions sharing the pool.
 *
 * Gated: skipped unless PgBouncer is reachable on 127.0.0.1:6432. Bring it up
 * with the command in tests/bin/setup-pgbouncer.sh.
 */
class PgBouncerTest extends TestCase
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
            'port' => 6432,
            'database' => 'rls_test',
            'username' => 'rls_app',
            'password' => 'secret',
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'disable',
            // Transaction pooling cannot carry server-side prepared statements
            // across the pool; emulate them client-side. (Alternatively
            // PgBouncer >= 1.21 with max_prepared_statements set.)
            'options' => [\PDO::ATTR_EMULATE_PREPARES => true],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('PgBouncer not reachable on 127.0.0.1:6432: ' . $e->getMessage());
        }

        foreach (RlsFunctions::statements() as $sql) {
            DB::statement($sql);
        }
    }

    protected function tearDown(): void
    {
        Rls::forget();
        parent::tearDown();
    }

    public function test_transaction_local_context_reaches_queries_through_pgbouncer(): void
    {
        Rls::actingAs(['tenant_id' => 'bouncer-tenant']);

        DB::transaction(function () {
            $this->assertSame(
                'bouncer-tenant',
                DB::selectOne("select rls.context('tenant_id') as v")->v,
            );
        });
    }

    public function test_each_transaction_gets_its_own_context_under_pooling(): void
    {
        Rls::actingAs(['tenant_id' => 'first']);
        DB::transaction(fn () => $this->assertSame(
            'first',
            DB::selectOne("select rls.context('tenant_id') as v")->v,
        ));
        Rls::forget();

        Rls::actingAs(['tenant_id' => 'second']);
        DB::transaction(fn () => $this->assertSame(
            'second',
            DB::selectOne("select rls.context('tenant_id') as v")->v,
        ));
    }

    public function test_committed_transaction_context_does_not_leak_on_the_pool(): void
    {
        Rls::actingAs(['tenant_id' => 'ephemeral']);

        DB::transaction(fn () => $this->assertSame(
            'ephemeral',
            DB::selectOne("select rls.context('tenant_id') as v")->v,
        ));

        // With context no longer active, a bare query must not observe the
        // prior transaction's local GUC on whatever pooled connection it lands
        // on — transaction-local settings reset at COMMIT.
        Rls::forget();
        $this->assertNull(DB::selectOne("select rls.context('tenant_id') as v")->v);
    }
}
