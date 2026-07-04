<?php

namespace Radiergummi\Rls;

use Illuminate\Support\ServiceProvider;

class RlsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rls.php', 'rls');
    }

    public function boot(): void
    {
        //
    }
}
