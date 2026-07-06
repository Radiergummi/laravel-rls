<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

use Illuminate\Contracts\Foundation\Application;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Radiergummi\LaravelRls\RlsServiceProvider;

final class Boot
{
    public static function app(): Application
    {
        return BootApplication::create(
            options: ['extra' => [
                'providers' => [RlsServiceProvider::class],
                // The functional API discovers all installed packages' providers by
                // default (unlike Orchestra\Testbench\TestCase, which discovers none),
                // which pulls in tpetry/laravel-postgresql-enhanced's pgsql connection
                // resolver here and collides with ours. Keep only the explicit list.
                'dont-discover' => ['*'],
            ]],
        );
    }
}

/**
 * Testbench application subclass for the bench harness.
 *
 * The functional `Testbench\Foundation\Application::create()` API's
 * `resolvingCallback` runs before the `config` binding exists (it fires from
 * `resolveApplicationResolvingCallback()`, ahead of `resolveApplicationConfiguration()`),
 * so it cannot be used to set connection config. `defineEnvironment()` is
 * called later, after providers are registered but before they boot -- the
 * earliest point config can be set -- so we override it here instead.
 *
 * @internal
 */
final class BootApplication extends Testbench
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $connection = static fn(string $user): array => [
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

        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', $connection('rls_app'));
        $app['config']->set('database.connections.pgsql_admin', $connection('rls_bypass'));
        $app['config']->set('rls.role_model', 'owner');
        $app['config']->set('rls.admin_connection', 'pgsql_admin');
    }
}
