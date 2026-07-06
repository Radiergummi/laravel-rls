<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\RlsServiceProvider;
use Radiergummi\LaravelRls\Tests\Fixtures\Support\RlsEnhancedConnection;
use Radiergummi\LaravelRls\Tests\TestCase;
use RuntimeException;
use Tpetry\PostgresqlEnhanced\PostgresEnhancedConnection;
use Tpetry\PostgresqlEnhanced\PostgresqlEnhancedServiceProvider;

#[TestDox('RLS works with a tpetry/laravel-postgresql-enhanced connection')]
class TpetryPostgresqlEnhancedInteropTest extends TestCase
{
    #[Test]
    #[TestDox('The connection is a composed class of postgresql-enhanced and RLS')]
    public function connection_composes_postgresql_enhanced_and_rls(): void
    {
        $connection = DB::connection();

        // postgresql-enhanced connection is the live base...
        $this->assertInstanceOf(PostgresEnhancedConnection::class, $connection);
        // ...with our RLS trait mixed in.
        $this->assertInstanceOf(RlsEnhancedConnection::class, $connection);
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('RLS context is still available in postgresql-enhanced')]
    public function rls_context_injection_still_works_under_postgresql_enhanced(): void
    {
        Rls::isolateTo(['tenant_id' => 'postgresql-enhanced-tenant']);

        $this->assertSame(
            'postgresql-enhanced-tenant',
            $this->selectSingleValueFromDatabase("select rls.context('tenant_id') as value"),
        );
    }

    protected function getPackageProviders($app): array
    {
        // postgresql-enhanced first, RLS last, so our resolver (registered in boot) wins and
        // returns the composed connection class.
        return [PostgresqlEnhancedServiceProvider::class, RlsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('rls.connection_class', RlsEnhancedConnection::class);
    }
}
