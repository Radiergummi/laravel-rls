<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AuditCommand extends Command
{
    protected $signature = 'rls:audit
        {--path=* : Directories to scan (defaults to the app directory)}
        {--threshold= : Fail (exit 1) if the bypass call-site count exceeds this number}';

    protected $description = 'Report every RLS bypass call site so bypass stays visible and reviewable';

    public function handle(): int
    {
        $paths = $this->option('path') ?: [base_path('app')];
        $pattern = '/(?:Rls::|\$?this->|->)\s*(withoutRls|system)\s*\(/';

        $findings = [];

        foreach ($paths as $path) {
            if (!File::isDirectory($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $lines = preg_split('/\R/', File::get($file->getPathname()));

                foreach ($lines as $number => $line) {
                    if (preg_match($pattern, $line, $matches)) {
                        $findings[] = [
                            'file' => $file->getRelativePathname(),
                            'line' => $number + 1,
                            'call' => $matches[1],
                        ];
                    }
                }
            }
        }

        foreach ($findings as $finding) {
            $this->line(
                sprintf(
                    '  %s:%d  %s()',
                    $finding['file'],
                    $finding['line'],
                    $finding['call'],
                ),
            );
        }

        $this->info(count($findings) . ' bypass call site(s) found.');

        $threshold = $this->option('threshold');

        if ($threshold !== null && count($findings) > (int) $threshold) {
            $this->error(
                sprintf(
                    'Bypass call sites (%s) exceed the threshold of %d.',
                    count($findings),
                    $threshold,
                ),
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
