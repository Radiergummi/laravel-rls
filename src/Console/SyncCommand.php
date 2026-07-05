<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Radiergummi\LaravelRls\Context\RlsManager;

class SyncCommand extends Command
{
    protected $signature = 'rls:sync';

    protected $description = 'Regenerate the typed rls.<key>() SQL helpers from the declared isolation keys';

    public function handle(RlsManager $manager): int
    {
        $schema = $manager->schema();

        if ($schema === null) {
            $this->warn('No context schema declared. Call Rls::defineContext() in your RlsServiceProvider.');

            return self::SUCCESS;
        }

        $statements = $schema->functionStatements();

        foreach ($statements as $sql) {
            DB::statement($sql);
        }

        $this->info(count($statements) . ' typed helper(s) synced.');

        return self::SUCCESS;
    }
}
