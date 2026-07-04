<?php

namespace Radiergummi\Rls;

use Illuminate\Support\ServiceProvider;

class RlsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rls.php', 'rls');

        $this->app->singleton('rls', fn () => new \Radiergummi\Rls\Context\RlsManager());
        $this->app->alias('rls', \Radiergummi\Rls\Context\RlsManager::class);
    }

    public function boot(): void
    {
        //
    }
}
