<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\Fixtures\Jobs\RecordContextJob;
use Radiergummi\LaravelRls\Tests\Fixtures\Jobs\RecordTenantJob;
use Radiergummi\LaravelRls\Tests\TestCase;

#[TestDox('Queued Job Context')]
class QueuedJobContextTest extends TestCase
{
    #[Test]
    #[TestDox('isolateTo() context propagates to a queued job')]
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

    #[Test]
    #[TestDox('Context reaches a job on a long-lived (daemon) worker, not only --once')]
    public function context_reaches_a_daemon_worker_job(): void
    {
        // A daemon worker resets scoped services between jobs; the manager must
        // read the live Context repository, not a stale one captured at boot.
        Rls::isolateTo(['tenant_id' => 'daemon-tenant']);
        RecordContextJob::dispatch('rls_daemon_ctx');
        Rls::forget();
        $this->assertFalse(Rls::hasContext());

        $this->artisan('queue:work', ['--stop-when-empty' => true]);

        $this->assertSame('daemon-tenant', Cache::get('rls_daemon_ctx'));
    }

    #[Test]
    #[TestDox("A job does not inherit the previous job's context on the same worker")]
    public function job_context_does_not_leak_to_the_next_job(): void
    {
        // Job A rides a tenant context on its payload; job B is dispatched with
        // none. Both drain through one daemon loop, so the Looping leak canary
        // runs at the boundary between them.
        Rls::isolateTo(['tenant_id' => 'job-a-tenant']);
        RecordContextJob::dispatch('rls_ctx_a');
        Rls::forget();

        RecordContextJob::dispatch('rls_ctx_b');
        $this->assertFalse(Rls::hasContext());

        $this->artisan('queue:work', ['--stop-when-empty' => true]);

        $this->assertSame('job-a-tenant', Cache::get('rls_ctx_a'), 'context did not reach its own job');
        $this->assertSame('<none>', Cache::get('rls_ctx_b'), "job A's context leaked into job B");
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        config(['queue.default' => 'database']);
        config(['queue.connections.database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'after_commit' => false,
        ]]);
        config(['cache.default' => 'array']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('jobs', static function (Blueprint $table): void {
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
