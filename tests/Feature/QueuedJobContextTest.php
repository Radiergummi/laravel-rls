<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Facades\Rls;
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
