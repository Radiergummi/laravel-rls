<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

use Illuminate\Contracts\Foundation\Application;
use Orchestra\Testbench\Foundation\Application as Testbench;
use PDO;
use Radiergummi\LaravelRls\RlsServiceProvider;

final class Boot
{
    public static function app(): Application
    {
        return BootApplication::create(
            options: ['extra' => [
                // Force the testing env through Testbench's own env-injection channel (applied
                // during app bootstrap, before providers boot). Class-level #[Bind]/#[Singleton]
                // attributes (e.g. BypassHandler -> DefaultBypassHandler, resolved in
                // RlsServiceProvider::boot()) only bind when the container has an environment
                // resolver, which the provider installs solely under the 'testing' env. Testbench
                // reports 'testing' only when APP_ENV says so: phpunit.xml sets it (so the smoke
                // test's run.php subprocess inherits it), but a bare `composer bench` shell does
                // not, leaving BypassHandler unresolvable. Set it here so the harness boots
                // identically however run.php is invoked.
                'env' => ['APP_ENV=testing'],
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
        $app['config']->set('database.connections.pgsql_pgbouncer', [
            ...$connection('rls_app'),
            'port' => 6432,
            // Mirror PgBouncerTest exactly: transaction pooling can't carry server-side prepared
            // statements, and the local pooler listener offers no TLS.
            'sslmode' => 'disable',
            'options' => [PDO::ATTR_EMULATE_PREPARES => true],
        ]);
        $app['config']->set('database.connections.pgsql_delayed', [
            ...$connection('rls_app'),
            'port' => 5433, // Toxiproxy proxy listen port
            'sslmode' => 'disable',
            'options' => [PDO::ATTR_EMULATE_PREPARES => true],
        ]);
        $app['config']->set('rls.role_model', 'owner');
        $app['config']->set('rls.admin_connection', 'pgsql_admin');
    }
}
