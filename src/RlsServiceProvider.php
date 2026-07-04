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
            $this->commands([\Radiergummi\Rls\Console\CheckCommand::class]);
        }

        Connection::resolverFor('pgsql', function ($pdo, $database, $prefix, $config) {
            $class = config('rls.connection_class', RlsPostgresConnection::class);

            return new $class($pdo, $database, $prefix, $config);
        });
    }

    public function boot(): void
    {
        $manager = $this->app->make('rls');

        $manager->setSyncCallback(function () {
            $connection = $this->app->make('db')->connection();

            if ($connection instanceof RlsPostgresConnection && $connection->transactionLevel() > 0) {
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
