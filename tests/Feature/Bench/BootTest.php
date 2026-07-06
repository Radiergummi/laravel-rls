<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature\Bench;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Boot;
use Radiergummi\LaravelRls\Database\RlsPostgresConnection;
use Radiergummi\LaravelRls\Tests\WithTestingUtils;

#[TestDox('Bench Boot')]
class BootTest extends TestCase
{
    use WithTestingUtils;

    /**
     * @throws BindingResolutionException
     */
    #[Test]
    #[TestDox('Boot::app() returns an app whose default connection is an RlsPostgresConnection')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function boots_a_real_rls_app(): void
    {
        $app = Boot::app();

        $connection = $app->make('db')->connection();
        $this->assertInstanceOf(RlsPostgresConnection::class, $connection);

        // A trivial query proves the connection is live and configured.
        $this->assertSame(
            'rls_app',
            $this->selectSingleValueFromDatabase('select current_user as value'),
        );

        // The admin connection is configured and bypasses RLS.
        $this->assertSame(
            'rls_bypass',
            $this->selectSingleValueFromDatabase(
                'select current_user as value',
                connectionName: 'pgsql_admin',
            ),
        );
    }

    #[Test]
    #[TestDox('Boot::app() registers the pgbouncer and delayed RLS connections')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function registers_endpoint_connections(): void
    {
        Boot::app();

        // Resolving a connection builds it lazily (no socket opened until a query), so these
        // assertions hold even when :6432 / :5433 are down.
        $this->assertInstanceOf(RlsPostgresConnection::class, DB::connection('pgsql_pgbouncer'));
        $this->assertInstanceOf(RlsPostgresConnection::class, DB::connection('pgsql_delayed'));
    }
}
