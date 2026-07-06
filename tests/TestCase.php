<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Radiergummi\LaravelRls\RlsServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [RlsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'rls_test',
            'username' => 'rls_app',
            'password' => 'secret',
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/database/migrations');
    }

    /**
     * Add a `pgsql_admin` connection as the BYPASSRLS role and point
     * rls.admin_connection at it, so bypass (system()/withoutIsolation()) has a
     * privileged connection to route to. Call after parent::defineEnvironment().
     */
    protected function useBypassAdminConnection(Application $app): void
    {
        $app['config']->set('database.connections.pgsql_admin', array_merge(
            $app['config']->get('database.connections.pgsql'),
            ['username' => 'rls_bypass'],
        ));
        $app['config']->set('rls.admin_connection', 'pgsql_admin');
    }
}
