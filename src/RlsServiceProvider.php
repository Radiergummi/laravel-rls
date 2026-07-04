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

        $this->app->singleton('rls', fn () => new RlsManager());
        $this->app->alias('rls', RlsManager::class);

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
    }
}
