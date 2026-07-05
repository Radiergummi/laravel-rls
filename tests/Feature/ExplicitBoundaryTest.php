<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Exceptions\MissingContextBoundary;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\RlsServiceProvider;
use RuntimeException;

/**
 * Explicit boundary mode fails loud when a context-bearing query hits an RLS-managed table outside
 * a transaction.
 *
 * Runs without RefreshDatabase, so queries execute at transaction level 0.
 */
#[TestDox('Explicit Boundary Mode')]
class ExplicitBoundaryTest extends TestCase
{
    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('Bare context query outside a transaction throws')]
    public function bare_context_query_outside_a_transaction_throws(): void
    {
        Rls::isolateTo(['tenant_id' => '11111111-1111-1111-1111-111111111111']);

        $this->expectException(MissingContextBoundary::class);

        DB::table('boundary_things')->count();
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('Query inside a transaction is allowed')]
    public function query_inside_a_transaction_is_allowed(): void
    {
        Rls::isolateTo(
            ['tenant_id' => '11111111-1111-1111-1111-111111111111'],
            fn() => DB::transaction(
                fn() => $this->assertSame(0, DB::table('boundary_things')->count()),
            ),
        );
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
            'port' => 5432,
            'database' => 'rls_test',
            'username' => 'rls_app',
            'password' => 'secret',
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]]);
        config(['rls.boundary' => 'explicit']);
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
}
