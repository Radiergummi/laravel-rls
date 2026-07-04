<?php

namespace Radiergummi\Rls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Radiergummi\Rls\Exceptions\AdminConnectionRequired;
use Radiergummi\Rls\Facades\Rls;
use Radiergummi\Rls\RlsServiceProvider;
use Radiergummi\Rls\Support\RlsFunctions;

/**
 * Restricted mode with two real roles: tables owned by rls_app (admin), the
 * app connecting as the non-owner rls_restricted. No RefreshDatabase — the
 * restricted connection must see committed data, and RLS must confine it
 * WITHOUT force (force only affects the owner).
 */
class RestrictedIsolationTest extends TestCase
{
    private string $a = '11111111-1111-1111-1111-111111111111';
    private string $b = '22222222-2222-2222-2222-222222222222';

    protected function getPackageProviders($app): array
    {
        return [RlsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $conn = fn (string $user) => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'rls_test',
            'username' => $user,
            'password' => 'secret',
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];

        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', $conn('rls_restricted'));
        $app['config']->set('database.connections.pgsql_admin', $conn('rls_app'));
        $app['config']->set('rls.role_model', 'restricted');
        $app['config']->set('rls.admin_connection', 'pgsql_admin');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $admin = DB::connection('pgsql_admin');

        foreach (RlsFunctions::statements() as $sql) {
            $admin->statement($sql);
        }

        $admin->statement('drop table if exists demo.things cascade');
        $admin->statement('create schema if not exists demo');
        $admin->statement('create table demo.things (id uuid primary key default gen_random_uuid(), tenant_id uuid not null)');
        $admin->statement('alter table demo.things enable row level security'); // NO force
        $admin->statement('create policy things_access on demo.things as permissive for all using (true) with check (true)');
        $admin->statement(
            "create policy things_iso on demo.things as restrictive for all " .
            "using (tenant_id = rls.context('tenant_id')::uuid) " .
            "with check (tenant_id = rls.context('tenant_id')::uuid)",
        );

        $admin->statement('grant usage on schema demo to rls_restricted');
        $admin->statement('grant select, insert, update, delete on demo.things to rls_restricted');
        $admin->statement('grant usage on schema rls to rls_restricted');
        $admin->statement('grant execute on all functions in schema rls to rls_restricted');

        // Seed as the owner (not forced -> bypasses RLS), committed so the
        // restricted connection can see it.
        $admin->table('demo.things')->insert([
            ['tenant_id' => $this->a],
            ['tenant_id' => $this->a],
            ['tenant_id' => $this->b],
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('pgsql_admin')->statement('drop table if exists demo.things cascade');
        Rls::forget();
        parent::tearDown();
    }

    public function test_force_is_off_yet_the_non_owner_is_confined(): void
    {
        $forced = DB::connection('pgsql_admin')
            ->selectOne("select relforcerowsecurity as f from pg_class where relname = 'things'")->f;
        $this->assertFalse((bool) $forced, 'sanity: FORCE is off');

        Rls::actingAs(['tenant_id' => $this->a], function () {
            $this->assertSame(2, DB::table('demo.things')->count());
        });

        Rls::actingAs(['tenant_id' => $this->b], function () {
            $this->assertSame(1, DB::table('demo.things')->count());
        });
    }

    public function test_missing_context_is_fail_closed(): void
    {
        $this->assertSame(0, DB::table('demo.things')->count());
    }

    public function test_system_routes_to_admin_connection_and_sees_all(): void
    {
        Rls::system('audit', function () {
            $this->assertSame(3, DB::table('demo.things')->count());
        });
    }

    public function test_system_hard_fails_without_an_admin_connection(): void
    {
        config(['rls.admin_connection' => null]);

        $this->expectException(AdminConnectionRequired::class);

        Rls::system('audit', fn () => null);
    }
}
