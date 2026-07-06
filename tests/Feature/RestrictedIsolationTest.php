<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Exceptions\AdminConnectionRequired;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\RlsServiceProvider;
use Radiergummi\LaravelRls\Support\RlsFunctions;
use Radiergummi\LaravelRls\Tests\WithTestingUtils;
use RuntimeException;
use Throwable;

/**
 * Restricted mode with two real roles: tables owned by rls_app (admin), the
 * app connecting as the non-owner rls_restricted. No RefreshDatabase — the
 * restricted connection must see committed data, and RLS must confine it
 * WITHOUT force (force only affects the owner).
 */
#[TestDox('Restricted Isolation')]
class RestrictedIsolationTest extends TestCase
{
    use WithTestingUtils;

    private string $a = '11111111-1111-1111-1111-111111111111';
    private string $b = '22222222-2222-2222-2222-222222222222';

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('The non-owner is confined even though FORCE row security is off')]
    public function force_is_off_yet_the_non_owner_is_confined(): void
    {
        $forced = $this->selectSingleValueFromDatabase(
            "select relforcerowsecurity as value from pg_class where relname = 'things'",
            connectionName: 'pgsql_admin',
        );

        $this->assertFalse((bool) $forced, 'sanity: FORCE is off');

        Rls::isolateTo(
            ['tenant_id' => $this->a],
            fn() => $this->assertSame(2, DB::table('demo.things')->count()),
        );

        Rls::isolateTo(
            ['tenant_id' => $this->b],
            fn() => $this->assertSame(1, DB::table('demo.things')->count()),
        );
    }

    #[Test]
    #[TestDox('Missing isolation context fails closed with zero rows')]
    public function missing_context_is_fail_closed(): void
    {
        $this->assertSame(0, DB::table('demo.things')->count());
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('system() routes to the admin connection and sees all rows')]
    public function system_routes_to_admin_connection_and_sees_all(): void
    {
        Rls::system(
            'audit',
            fn() => $this->assertSame(3, DB::table('demo.things')->count()),
        );
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('system() hard fails without a configured admin connection')]
    public function system_hard_fails_without_an_admin_connection(): void
    {
        config(['rls.admin_connection' => null]);

        $this->expectException(AdminConnectionRequired::class);

        Rls::system('audit', static fn() => null);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    #[TestDox('The restricted role cannot self-escape isolation via the bypass GUC')]
    public function restricted_role_cannot_self_escape_via_bypass_guc(): void
    {
        // As the restricted role, set the (now-retired) app.bypass GUC directly and a tenant.
        // No policy references app.bypass anymore — it is an inert custom GUC — so only tenant A's
        // rows remain visible.
        DB::transaction(function (): void {
            DB::statement("select set_config('app.bypass', 'on', true)");
            DB::statement("select set_config('app.tenant_id', ?, true)", [$this->a]);

            $this->assertSame(
                2,
                DB::table('demo.things')->count(),
                'bypass GUC must be inert for a non-owner',
            );
        });
    }

    protected function getPackageProviders($app): array
    {
        return [RlsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $connection = static fn(string $user): array
            => [
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

        config(['database.default' => 'pgsql']);
        config(['database.connections.pgsql' => $connection('rls_restricted')]);
        config(['database.connections.pgsql_admin' => $connection('rls_app')]);
        config(['rls.role_model' => 'restricted']);
        config(['rls.admin_connection' => 'pgsql_admin']);
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
        $admin->statement(
            'create table demo.things (id uuid primary key default gen_random_uuid(), tenant_id uuid not null)',
        );
        $admin->statement('alter table demo.things enable row level security'); // NO force
        $admin->statement(
            'create policy things_access on demo.things as permissive for all using (true) with check (true)',
        );
        $admin->statement(
            'create policy things_iso on demo.things as restrictive for all '
            . "using (tenant_id = rls.context('tenant_id')::uuid) "
            . "with check (tenant_id = rls.context('tenant_id')::uuid)",
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
}
