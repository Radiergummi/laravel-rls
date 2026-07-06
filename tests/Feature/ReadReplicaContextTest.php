<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\RlsServiceProvider;
use Radiergummi\LaravelRls\Support\RlsFunctions;
use Radiergummi\LaravelRls\Tests\WithTestingUtils;
use RuntimeException;
use stdClass;

/**
 * A read/write-split connection has a separate read PDO (the replica) that plain SELECT's route to
 * outside a transaction.
 *
 * Session-strategy GUCs are set on the Write PDO, so the replica must be given the same context, or
 * Reads see none of it. Session strategy exercises the gap (under the transaction/wrap strategy
 * in-transaction reads use the Write PDO instead).
 */
#[TestDox('Read Replica Context')]
class ReadReplicaContextTest extends TestCase
{
    use WithTestingUtils;

    #[Test]
    #[TestDox('Read and write queries use distinct backends')]
    public function read_and_write_use_distinct_backends(): void
    {
        $readPid = DB::selectOne('select pg_backend_pid() as value');
        $this->assertIsObject($readPid);
        $this->assertInstanceOf(stdClass::class, $readPid);
        $this->assertObjectHasProperty('value', $readPid);
        $this->assertIsInt($readPid->value);
        $writePid = DB::selectOne('select pg_backend_pid() as value', useReadPdo: false);
        $this->assertIsObject($writePid);
        $this->assertInstanceOf(stdClass::class, $writePid);
        $this->assertObjectHasProperty('value', $writePid);

        $this->assertNotSame(
            $readPid->value,
            $writePid->value,
            'expected a real read/write split',
        );
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('The read replica PDO sees the session context')]
    public function read_replica_pdo_sees_session_context(): void
    {
        Rls::isolateTo(['tenant_id' => 'replica-tenant']);

        // A plain SELECT routes to the read PDO (a separate backend session).
        $value = $this->selectSingleValueFromDatabase("select rls.context('tenant_id') as value");

        $this->assertSame('replica-tenant', $value);
    }

    protected function getPackageProviders($app): array
    {
        return [RlsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        config(['database.default' => 'pgsql']);
        config([
            'database.connections.pgsql' => [
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
            ],
        ]);
        config(['rls.strategy' => 'session']);
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
        DB::select("select set_config('app.tenant_id', '', false)");
        Rls::forget();
        parent::tearDown();
    }
}
