<?php

namespace Radiergummi\Rls;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use Radiergummi\Rls\Context\RlsManager;
use Radiergummi\Rls\Database\RlsPostgresConnection;

class RlsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rls.php', 'rls');

        $this->app->singleton('rls', fn ($app) => new RlsManager($app->make(\Illuminate\Log\Context\Repository::class)));
        $this->app->alias('rls', RlsManager::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Radiergummi\Rls\Console\CheckCommand::class,
                \Radiergummi\Rls\Console\AuditCommand::class,
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
            if (method_exists($connection, 'applyRlsContext') && $connection->transactionLevel() > 0) {
                $connection->applyRlsContext();
            }
        });

        \Radiergummi\Rls\Schema\RlsSchemaMacros::register();

        \Illuminate\Support\Facades\Context::dehydrating(
            fn ($context) => RlsManager::stripBypassOnDehydrate($context),
        );

        $this->app['events']->listen(
            \Illuminate\Auth\Events\Authenticated::class,
            fn ($event) => $manager->establishFromUser($event->user),
        );

        if (config('rls.role_model') === 'restricted') {
            $manager->setBypassHandler(function (string $reason, \Closure $callback) {
                $admin = config('rls.admin_connection');

                if ($admin === null) {
                    throw \Radiergummi\Rls\Exceptions\AdminConnectionRequired::forReason($reason);
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
