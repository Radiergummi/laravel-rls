<?php

namespace Radiergummi\LaravelRls\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncCommand extends Command
{
    protected $signature = 'rls:sync';

    protected $description = 'Regenerate the typed rls.<dimension>() SQL helpers from the declared ContextSchema';

    public function handle(): int
    {
        $schema = app('rls')->schema();

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
