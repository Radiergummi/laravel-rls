<?php

namespace Radiergummi\Rls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Radiergummi\Rls\Exceptions\MissingContextBoundary;
use Radiergummi\Rls\Facades\Rls;
use Radiergummi\Rls\RlsServiceProvider;

/**
 * Explicit boundary mode fails loud when a context-bearing query hits an
 * RLS-managed table outside a transaction. Runs without RefreshDatabase so
 * queries execute at transaction level 0.
 */
class ExplicitBoundaryTest extends TestCase
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
        $app['config']->set('rls.boundary', 'explicit');
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('drop table if exists boundary_things');
        DB::statement('create table boundary_things (id serial primary key, tenant_id uuid)');
        DB::statement('alter table boundary_things enable row level security');
    }

    protected function tearDown(): void
    {
        DB::statement('drop table if exists boundary_things');
        Rls::forget();
        parent::tearDown();
    }

    public function test_bare_context_query_outside_a_transaction_throws(): void
    {
        Rls::actingAs(['tenant_id' => '11111111-1111-1111-1111-111111111111']);

        $this->expectException(MissingContextBoundary::class);

        DB::table('boundary_things')->count();
    }

    public function test_query_inside_a_transaction_is_allowed(): void
    {
        Rls::actingAs(['tenant_id' => '11111111-1111-1111-1111-111111111111'], function () {
            DB::transaction(function () {
                $this->assertSame(0, DB::table('boundary_things')->count());
            });
        });
    }
}
