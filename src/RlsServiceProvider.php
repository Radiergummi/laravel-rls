<?php

namespace Radiergummi\LaravelRls;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use Radiergummi\LaravelRls\Context\RlsManager;
use Radiergummi\LaravelRls\Database\RlsPostgresConnection;

class RlsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rls.php', 'rls');

        $this->app->singleton('rls', fn ($app) => new RlsManager($app->make(\Illuminate\Log\Context\Repository::class)));
        $this->app->alias('rls', RlsManager::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Radiergummi\LaravelRls\Console\CheckCommand::class,
                \Radiergummi\LaravelRls\Console\AuditCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        // Registered in boot() (not register()) so it wins over other pgsql
        // connection packages that register their resolver in boot(). Point
        // rls.connection_class at a class extending theirs to compose.
        Connection::resolverFor('pgsql', function ($pdo, $database, $prefix, $config) {
            $class = config('rls.connection_class', RlsPostgresConnection::class);

            return new $class($pdo, $database, $prefix, $config);
        });

        $manager = $this->app->make('rls');

        $manager->setSyncCallback(function () {
            $connection = $this->app->make('db')->connection();

            // Capability check (not instanceof) so composed connections built
            // on other pgsql packages (e.g. tpetry) are recognised too.
            // applyRlsContext() self-guards per strategy (no-op for the
            // transaction strategy outside a transaction).
            if (method_exists($connection, 'applyRlsContext')) {
                $connection->applyRlsContext();
            }
        });

        \Radiergummi\LaravelRls\Schema\RlsSchemaMacros::register();

        \Illuminate\Support\Facades\Context::dehydrating(
            fn ($context) => RlsManager::stripBypassOnDehydrate($context),
        );

        $this->app['events']->listen(
            \Illuminate\Auth\Events\Authenticated::class,
            fn ($event) => $manager->establishFromUser($event->user),
        );

        // Leak canary: on long-lived workers a context that was never popped
        // would carry into the next job/request (cross-tenant hazard). Check at
        // each boundary. Octane is optional, so guard its event class.
        //
        // For the queue we hook `Looping` (fired between jobs in the daemon
        // loop, before Laravel hydrates the next job's context) rather than
        // `JobProcessing` (which fires *after* hydration and would flag every
        // job's own context as a leak). `--once` runs a fresh process, so it
        // needs no check. The listener must return null, not false, or it would
        // veto the worker's `until(Looping)` loop.
        $this->app['events']->listen(
            \Illuminate\Queue\Events\Looping::class,
            function () use ($manager) {
                $manager->checkForLeak('job');
            },
        );

        if (class_exists(\Laravel\Octane\Events\RequestReceived::class)) {
            $this->app['events']->listen(
                \Laravel\Octane\Events\RequestReceived::class,
                fn () => $manager->checkForLeak('request'),
            );
        }

        if (config('rls.role_model') === 'restricted') {
            $manager->setBypassHandler(function (string $reason, \Closure $callback) {
                $admin = config('rls.admin_connection');

                if ($admin === null) {
                    throw \Radiergummi\LaravelRls\Exceptions\AdminConnectionRequired::forReason($reason);
                }

                $db = $this->app->make('db');
                $previous = $db->getDefaultConnection();
                $db->setDefaultConnection($admin);

                try {
                    return $callback();
                } finally {
                    $db->setDefaultConnection($previous);
                }
            });
        }
    }
}
