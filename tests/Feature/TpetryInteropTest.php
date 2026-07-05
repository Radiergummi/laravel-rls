<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\RlsServiceProvider;
use Radiergummi\LaravelRls\Tests\Support\RlsEnhancedConnection;
use Radiergummi\LaravelRls\Tests\TestCase;
use Tpetry\PostgresqlEnhanced\PostgresEnhancedConnection;
use Tpetry\PostgresqlEnhanced\PostgresqlEnhancedServiceProvider;

class TpetryInteropTest extends TestCase
{
    #[Test]
    public function connection_composes_tpetry_and_rls(): void
    {
        $connection = DB::connection();

        // tpetry's enhanced connection is the live base...
        $this->assertInstanceOf(PostgresEnhancedConnection::class, $connection);
        // ...with our RLS trait mixed in.
        $this->assertInstanceOf(RlsEnhancedConnection::class, $connection);
    }

    #[Test]
    public function rls_context_injection_still_works_under_tpetry(): void
    {
        Rls::isolateTo(['tenant_id' => 'tpetry-tenant']);

        $this->assertSame('tpetry-tenant', DB::selectOne("select rls.context('tenant_id') as v")->v);
    }

    protected function getPackageProviders($app): array
    {
        // tpetry first, RLS last, so our resolver (registered in boot) wins and
        // returns the composed connection class.
        return [PostgresqlEnhancedServiceProvider::class, RlsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('rls.connection_class', RlsEnhancedConnection::class);
    }
}
