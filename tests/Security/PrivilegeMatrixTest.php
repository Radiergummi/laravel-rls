<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\RlsServiceProvider;
use Radiergummi\LaravelRls\Support\RlsFunctions;
use Radiergummi\LaravelRls\Tests\WithTestingUtils;

/**
 * Threat category 5 — the role/privilege matrix. States precisely who a policy
 * confines, on one isolated table queried as each role class:
 *
 *  - owner without FORCE  → NOT confined (Postgres exempts a table's owner)
 *  - owner with FORCE     → confined
 *  - non-owner (restricted) → confined, FORCE or not (only owners are exempt)
 *  - BYPASSRLS role       → skips policies (documented no-op)
 *  - superuser            → skips policies (documented no-op)
 *
 * And SET ROLE cannot be used to climb from the restricted role into a
 * privileged one it is not a member of. No RefreshDatabase: the data is seeded
 * committed so every role's own connection can read it.
 *
 * @see docs/MILESTONES.md — Milestone B, threat category 5
 */
#[TestDox('Security: role/privilege matrix')]
class PrivilegeMatrixTest extends TestCase
{
    use WithTestingUtils;

    private string $a = '11111111-1111-1111-1111-111111111111';
    private string $b = '22222222-2222-2222-2222-222222222222';

    #[Test]
    #[TestDox('An owner without FORCE is not confined by the policy')]
    public function owner_without_force_is_not_confined(): void
    {
        // rls_app owns the table; without FORCE, Postgres exempts the owner.
        $this->assertSame(3, DB::table('matrix_things')->count());
    }

    #[Test]
    #[TestDox('An owner with FORCE is confined like anyone else')]
    public function owner_with_force_is_confined(): void
    {
        // Only the owner can alter the table; rls_app owns it.
        DB::connection('pgsql')->statement('alter table matrix_things force row level security');

        Rls::isolateTo(
            ['tenant_id' => $this->a],
            fn() => $this->assertSame(2, DB::table('matrix_things')->count()),
        );

        $this->assertSame(0, DB::table('matrix_things')->count(), 'FORCE-bound owner leaked rows with no context');
    }

    #[Test]
    #[TestDox('A non-owner is confined even with FORCE off (FORCE only affects the owner)')]
    public function non_owner_is_confined_without_force(): void
    {
        Rls::isolateTo(
            ['tenant_id' => $this->a],
            fn() => $this->assertSame(2, DB::connection('pgsql_restricted')->table('matrix_things')->count()),
        );

        $this->assertSame(
            0,
            DB::connection('pgsql_restricted')->table('matrix_things')->count(),
            'A non-owner saw rows with no context set',
        );
    }

    #[Test]
    #[TestDox('A BYPASSRLS role skips the policy entirely (documented no-op)')]
    public function bypassrls_role_skips_the_policy(): void
    {
        $this->assertSame(3, DB::connection('pgsql_admin')->table('matrix_things')->count());
    }

    #[Test]
    #[TestDox('A superuser skips the policy entirely (documented no-op)')]
    public function superuser_skips_the_policy(): void
    {
        $this->assertSame(3, DB::connection('pgsql_super')->table('matrix_things')->count());
    }

    #[Test]
    #[TestDox('The restricted role cannot SET ROLE into a privileged role it is not a member of')]
    public function set_role_cannot_escalate_out_of_isolation(): void
    {
        try {
            DB::connection('pgsql_restricted')->statement('set role rls_bypass');

            $this->fail('Expected SET ROLE to a non-member role to be denied');
        } catch (QueryException $exception) {
            $this->assertStringContainsStringIgnoringCase('permission denied', $exception->getMessage());
        }
    }

    protected function getPackageProviders($app): array
    {
        return [RlsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $connection = static fn(string $user, string $password = 'secret'): array => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'rls_test',
            'username' => $user,
            'password' => $password,
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];

        config(['database.default' => 'pgsql']);
        config(['database.connections.pgsql' => $connection('rls_app')]);
        config(['database.connections.pgsql_admin' => $connection('rls_bypass')]);
        config(['database.connections.pgsql_restricted' => $connection('rls_restricted')]);
        config(['database.connections.pgsql_super' => $connection('postgres', 'postgres')]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $owner = DB::connection('pgsql');

        foreach (RlsFunctions::statements() as $sql) {
            $owner->statement($sql);
        }

        $owner->statement('drop table if exists matrix_things cascade');
        $owner->statement(
            'create table matrix_things (id uuid primary key default gen_random_uuid(), tenant_id uuid not null)',
        );
        $owner->statement('alter table matrix_things enable row level security'); // NO force by default
        $owner->statement(
            'create policy matrix_things_access on matrix_things as permissive for all using (true) with check (true)',
        );
        $owner->statement(
            'create policy matrix_things_iso on matrix_things as restrictive for all '
            . "using (tenant_id = rls.context('tenant_id')::uuid) "
            . "with check (tenant_id = rls.context('tenant_id')::uuid)",
        );

        // The non-owner needs to call the policy's rls.context() helper (table
        // access comes from the default privileges in setup-db.sh).
        $owner->statement('grant usage on schema rls to rls_restricted');
        $owner->statement('grant execute on all functions in schema rls to rls_restricted');

        // Seed as the owner (FORCE off -> bypasses RLS), committed so every role's
        // connection can read it: two rows for tenant A, one for tenant B.
        $owner->table('matrix_things')->insert([
            ['tenant_id' => $this->a],
            ['tenant_id' => $this->a],
            ['tenant_id' => $this->b],
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('pgsql')->statement('drop table if exists matrix_things cascade');
        Rls::forget();

        parent::tearDown();
    }
}
