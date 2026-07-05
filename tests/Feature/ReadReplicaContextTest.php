<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\RlsServiceProvider;
use Radiergummi\LaravelRls\Support\RlsFunctions;

/**
 * A read/write-split connection has a separate read PDO (the replica) that
 * plain SELECTs route to outside a transaction. Session-strategy GUCs are set
 * on the write PDO, so the replica must be given the same context or reads see
 * none of it. Session strategy exercises the gap (under the transaction/wrap
 * strategy in-transaction reads use the write PDO instead).
 */
class ReadReplicaContextTest extends TestCase
{
    #[Test]
    public function read_and_write_use_distinct_backends(): void
    {
        $readPid = DB::select('select pg_backend_pid() as p')[0]->p;
        $writePid = DB::select('select pg_backend_pid() as p', [], false)[0]->p;

        $this->assertNotSame($readPid, $writePid, 'expected a real read/write split');
    }

    #[Test]
    public function read_replica_pdo_sees_session_context(): void
    {
        Rls::actingAs(['tenant_id' => 'replica-tenant']);

        // A plain select routes to the read PDO (a separate backend session).
        $value = DB::selectOne("select rls.context('tenant_id') as v")->v;

        $this->assertSame('replica-tenant', $value);
    }

    protected function getPackageProviders($app): array
    {
        return [RlsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'read' => ['host' => ['127.0.0.1']],
            'write' => ['host' => ['127.0.0.1']],
            'port' => 5432,
            'database' => 'rls_test',
            'username' => 'rls_app',
            'password' => 'secret',
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
            'sticky' => false,
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
        DB::statement("select set_config('app.tenant_id', '', false)");
        DB::select("select set_config('app.tenant_id', '', false)", [], true);
        Rls::forget();
        parent::tearDown();
    }
}
