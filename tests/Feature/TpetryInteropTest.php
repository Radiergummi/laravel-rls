<?php

namespace Radiergummi\Rls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Radiergummi\Rls\Facades\Rls;
use Radiergummi\Rls\RlsServiceProvider;
use Radiergummi\Rls\Tests\Support\RlsEnhancedConnection;
use Radiergummi\Rls\Tests\TestCase;
use Tpetry\PostgresqlEnhanced\PostgresEnhancedConnection;
use Tpetry\PostgresqlEnhanced\PostgresqlEnhancedServiceProvider;

class TpetryInteropTest extends TestCase
{
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

    public function test_connection_composes_tpetry_and_rls(): void
    {
        $connection = DB::connection();

        // tpetry's enhanced connection is the live base...
        $this->assertInstanceOf(PostgresEnhancedConnection::class, $connection);
        // ...with our RLS trait mixed in.
        $this->assertInstanceOf(RlsEnhancedConnection::class, $connection);
    }

    public function test_rls_context_injection_still_works_under_tpetry(): void
    {
        Rls::actingAs(['tenant_id' => 'tpetry-tenant']);

        $this->assertSame('tpetry-tenant', DB::selectOne("select rls.context('tenant_id') as v")->v);
    }
}
