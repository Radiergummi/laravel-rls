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
use RuntimeException;

/**
 * Owner mode (tables owned by the app role rls_app, FORCE row security on) with
 * the equality-only isolation predicate. Bypass no longer lives in-band — it
 * routes to a privileged BYPASSRLS admin connection (rls_bypass), the same
 * machinery restricted mode uses. No RefreshDatabase: the owner is FORCE-bound,
 * so it must read committed data through the policy, and the admin connection
 * must see all of it.
 */
#[TestDox('Owner Mode Bypass')]
class OwnerModeBypassTest extends TestCase
{
    private string $a = '11111111-1111-1111-1111-111111111111';
    private string $b = '22222222-2222-2222-2222-222222222222';

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('The FORCE-bound owner is confined by the equality-only predicate')]
    public function scoped_reads_are_confined_under_force(): void
    {
        $forced = DB::connection('pgsql_admin')
            ->selectOne("select relforcerowsecurity as value from pg_class where relname = 'owner_things'")->value;
        $this->assertTrue((bool) $forced, 'sanity: FORCE is on in owner mode');

        Rls::isolateTo(
            ['tenant_id' => $this->a],
            fn() => $this->assertSame(2, DB::table('owner_things')->count()),
        );

        Rls::isolateTo(
            ['tenant_id' => $this->b],
            fn() => $this->assertSame(1, DB::table('owner_things')->count()),
        );
    }

    #[Test]
    #[TestDox('Missing isolation context fails closed with zero rows')]
    public function missing_context_is_fail_closed(): void
    {
        $this->assertSame(0, DB::table('owner_things')->count());
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('system() routes to the admin connection and sees all tenants')]
    public function system_routes_to_admin_connection_and_sees_all(): void
    {
        Rls::system(
            'audit',
            fn() => $this->assertSame(3, DB::table('owner_things')->count()),
        );
    }

    #[Test]
    #[TestDox('system() hard fails without a configured admin connection')]
    public function system_hard_fails_without_an_admin_connection(): void
    {
        config(['rls.admin_connection' => null]);

        $this->expectException(AdminConnectionRequired::class);

        Rls::system('audit', fn() => null);
    }

    protected function getPackageProviders($app): array
    {
        return [RlsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $conn = fn(string $user)
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
        config(['database.connections.pgsql' => $conn('rls_app')]);
        config(['database.connections.pgsql_admin' => $conn('rls_bypass')]);
        config(['rls.role_model' => 'owner']);
        config(['rls.admin_connection' => 'pgsql_admin']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The owner (rls_app) installs the helper and owns the table; FORCE binds
        // even the owner to the policy.
        foreach (RlsFunctions::statements() as $sql) {
            DB::statement($sql);
        }

        DB::statement('drop table if exists owner_things cascade');
        DB::statement(
            'create table owner_things (id uuid primary key default gen_random_uuid(), tenant_id uuid not null)',
        );
        DB::statement('alter table owner_things enable row level security');
        DB::statement('alter table owner_things force row level security');
        DB::statement(
            'create policy owner_things_access on owner_things as permissive for all using (true) with check (true)',
        );
        DB::statement(
            'create policy owner_things_iso on owner_things as restrictive for all '
            . "using (tenant_id = rls.context('tenant_id')::uuid) "
            . "with check (tenant_id = rls.context('tenant_id')::uuid)",
        );

        // Seed through the BYPASSRLS admin connection (skips FORCE + WITH CHECK),
        // committed so the FORCE-bound owner and the admin connection can read it.
        DB::connection('pgsql_admin')->table('owner_things')->insert([
            ['tenant_id' => $this->a],
            ['tenant_id' => $this->a],
            ['tenant_id' => $this->b],
        ]);
    }

    protected function tearDown(): void
    {
        DB::statement('drop table if exists owner_things cascade');
        Rls::forget();
        parent::tearDown();
    }
}
