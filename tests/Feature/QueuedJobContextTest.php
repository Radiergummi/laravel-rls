<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\Jobs\RecordTenantJob;
use Radiergummi\LaravelRls\Tests\TestCase;

class QueuedJobContextTest extends TestCase
{
    #[Test]
    public function tenant_context_propagates_to_a_queued_job(): void
    {
        Rls::isolateTo(['tenant_id' => 'job-tenant']);
        RecordTenantJob::dispatch();

        // Clear the dispatcher's context so a pass can only come from the
        // context that rode the job payload, not shared in-process state.
        Rls::forget();
        $this->assertFalse(Rls::hasContext());

        $this->artisan('queue:work', ['--once' => true]);

        $this->assertSame('job-tenant', Cache::get('rls_job_tenant'));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'after_commit' => false,
        ]);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('jobs', function ($table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('jobs');
        parent::tearDown();
    }
}
