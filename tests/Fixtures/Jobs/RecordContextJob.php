<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Radiergummi\LaravelRls\Facades\Rls;

/**
 * Records the tenant this job observes under a caller-supplied cache key, so a
 * test can dispatch several jobs and assert each ran under its own context (or
 * none) — proving one job's context neither goes missing nor leaks into the next
 * on a long-lived worker.
 */
class RecordContextJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly string $cacheKey) {}

    public function handle(): void
    {
        Cache::put($this->cacheKey, Rls::get('tenant_id') ?? '<none>');
    }
}
