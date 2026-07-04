<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Radiergummi\LaravelRls\Facades\Rls;

class RecordTenantJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        Cache::put('rls_job_tenant', Rls::get('tenant_id') ?? '<none>');
    }
}
