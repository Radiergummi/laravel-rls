<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\RlsServiceProvider;
use Radiergummi\LaravelRls\Support\RlsFunctions;
use RuntimeException;
use Throwable;

/**
 * Proves the transaction strategy against a real PgBouncer in transaction pooling mode —
 * the deployment the design targets. Each transaction may land on a different pooled server
 * connection, so context must be (re)injected at every BEGIN (which the transaction-local GUC
 * mechanism does) and must not leak between transactions sharing the pool.
 *
 * Gated: skipped unless PgBouncer is reachable on 127.0.0.1:6432. Bring it up with the command in
 * tests/bin/setup-pgbouncer.sh.
 */
#[TestDox('PgBouncer')]
class PgBouncerTest extends TestCase
{
    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     * @throws Throwable
     */
    #[Test]
    #[TestDox('Transaction-local context reaches queries through PgBouncer')]
    public function transaction_local_context_reaches_queries_through_pgbouncer(): void
    {
        Rls::isolateTo(['tenant_id' => 'bouncer-tenant']);

        DB::transaction(fn()
            => $this->assertSame(
                'bouncer-tenant',
                DB::selectOne("select rls.context('tenant_id') as value")->value,
            ));
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     * @throws Throwable
     */
    #[Test]
    #[TestDox('Each transaction gets its own context under pooling')]
    public function each_transaction_gets_its_own_context_under_pooling(): void
    {
        Rls::isolateTo(['tenant_id' => 'first']);
        DB::transaction(fn()
            => $this->assertSame(
                'first',
                DB::selectOne("select rls.context('tenant_id') as value")->value,
            ));
        Rls::forget();

        Rls::isolateTo(['tenant_id' => 'second']);
        DB::transaction(fn()
            => $this->assertSame(
                'second',
                DB::selectOne("select rls.context('tenant_id') as value")->value,
            ));
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     * @throws Throwable
     */
    #[Test]
    #[TestDox('Committed transaction context does not leak on the pool')]
    public function committed_transaction_context_does_not_leak_on_the_pool(): void
    {
        Rls::isolateTo(['tenant_id' => 'ephemeral']);

        DB::transaction(fn()
            => $this->assertSame(
                'ephemeral',
                DB::selectOne("select rls.context('tenant_id') as value")->value,
            ));

        // With context no longer active, a bare query must not observe the prior transaction's
        // local GUC on whatever pooled connection it lands on — transaction-local settings reset
        // at COMMIT.
        Rls::forget();
        $this->assertNull(DB::selectOne("select rls.context('tenant_id') as value")->value);
    }

    protected function getPackageProviders($app): array
    {
        return [RlsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        config(['database.default' => 'pgsql']);
        config(['database.connections.pgsql' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 6432,
            'database' => 'rls_test',
            'username' => 'rls_app',
            'password' => 'secret',
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'disable',
            // Transaction pooling cannot carry server-side prepared statements across the pool;
            // emulate them client-side. (Alternatively PgBouncer >= 1.21 with
            // max_prepared_statements set.)
            'options' => [PDO::ATTR_EMULATE_PREPARES => true],
        ]]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection()->getPdo();
        } catch (Throwable $exception) {
            $this->markTestSkipped(
                "PgBouncer not reachable on 127.0.0.1:6432: {$exception->getMessage()}",
            );
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
}
