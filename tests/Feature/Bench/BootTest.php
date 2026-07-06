<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature\Bench;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Boot;
use Radiergummi\LaravelRls\Database\RlsPostgresConnection;

#[TestDox('Bench Boot')]
class BootTest extends TestCase
{
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
        $this->assertSame('rls_app', $connection->selectOne('select current_user as u')->u);

        // The admin connection is configured and bypasses RLS.
        $this->assertSame('rls_bypass', $app->make('db')->connection('pgsql_admin')->selectOne('select current_user as u')->u);
    }
}
