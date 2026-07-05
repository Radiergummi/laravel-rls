<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Radiergummi\LaravelRls\Context\RlsManager;
use Radiergummi\LaravelRls\Exceptions\RlsContextLeaked;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;

class LeakCanaryTest extends TestCase
{
    /**
     * @throws RlsContextLeaked
     */
    #[Test]
    public function logs_and_clears_a_leaked_context(): void
    {
        $log = Log::spy();
        Rls::isolateTo(['tenant_id' => 'leaked-from-previous-job']);

        app(RlsManager::class)->checkForLeak('job');

        /** @noinspection PhpUndefinedMethodInspection */
        $log->shouldHaveReceived('critical')->once();
        $this->assertFalse(Rls::hasContext(), 'leaked context must be cleared before the new unit of work runs');
    }

    /**
     * @throws RlsContextLeaked
     */
    #[Test]
    public function clean_stack_is_silent(): void
    {
        $log = Log::spy();

        app(RlsManager::class)->checkForLeak('job');

        $log->shouldNotHaveReceived('critical');
    }

    #[Test]
    public function throw_mode_raises_and_still_clears(): void
    {
        config(['rls.leak_canary' => 'throw']);
        Rls::isolateTo(['tenant_id' => 'leaked']);

        try {
            app(RlsManager::class)->checkForLeak('request');
            $this->fail('Expected RlsContextLeaked to be thrown');
        } catch (RlsContextLeaked) {
            $this->assertFalse(Rls::hasContext(), 'context must be cleared even when throwing');
        }
    }

    /**
     * @throws RlsContextLeaked
     */
    #[Test]
    public function off_mode_does_nothing(): void
    {
        config(['rls.leak_canary' => 'off']);
        $log = Log::spy();
        Rls::isolateTo(['tenant_id' => 'leaked']);

        app(RlsManager::class)->checkForLeak('job');

        $log->shouldNotHaveReceived('critical');
        Rls::forget();
    }

    #[Test]
    public function registers_queue_looping_listener(): void
    {
        $this->assertTrue(Event::hasListeners(Looping::class));
    }
}
