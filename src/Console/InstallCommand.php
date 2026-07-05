<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'rls:install {--force : Overwrite any existing published files}';

    protected $description = 'Publish the config, SQL-functions migration, and app-side RlsServiceProvider';

    public function handle(): int
    {
        $force = ['--force' => (bool) $this->option('force')];

        $this->callSilent('vendor:publish', ['--tag' => 'rls-config'] + $force);
        $this->callSilent('vendor:publish', ['--tag' => 'rls-migrations'] + $force);
        $this->callSilent('vendor:publish', ['--tag' => 'rls-provider'] + $force);

        $this->info('laravel-rls installed.');
        $this->line('Next steps:');
        $this->line('  1. Register App\\Providers\\RlsServiceProvider in bootstrap/providers.php');
        $this->line('  2. Edit it to declare your isolation keys and identity mapping');
        $this->line('  3. Run: php artisan migrate   (installs the rls.* SQL helpers)');
        $this->line('  4. Scope tables with $table->isolatedBy(...) in your migrations');

        return self::SUCCESS;
    }
}
