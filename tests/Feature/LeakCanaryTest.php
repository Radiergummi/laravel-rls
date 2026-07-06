<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\ExpectationFailedException;
use Psr\Log\LoggerInterface;
use Radiergummi\LaravelRls\Context\RlsManager;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Exceptions\RlsContextLeaked;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;
use RuntimeException;

#[TestDox('Leak Canary')]
class LeakCanaryTest extends TestCase
{
    /**
     * @throws ExpectationFailedException
     * @throws InvalidContextValue
     * @throws RlsContextLeaked
     * @throws RuntimeException
     * @throws BindingResolutionException
     */
    #[Test]
    #[TestDox('Logs a critical message and clears a leaked context')]
    public function logs_and_clears_a_leaked_context(): void
    {
        $logger = Mockery::spy(LoggerInterface::class);
        $logManager = $this->app->make(LogManager::class);

        // extend() only fires for a channel whose configured driver matches the extension name, so
        // the 'rls' channel must exist in config or channel('rls') falls back to the emergency
        // logger and the spy is never consulted.
        config(['logging.channels.rls' => ['driver' => 'rls']]);
        $logManager->forgetChannel('rls');
        $logManager->extend('rls', fn() => $logger);

        // The manager is a singleton built at boot, so it captured the real 'rls' channel logger
        // before the swap above. Drop it and rebuild so it resolves the mocked channel.
        $this->app->forgetInstance(RlsManager::class);
        $manager = $this->app->make(RlsManager::class);

        $manager->isolateTo(['tenant_id' => 'leaked-from-previous-job']);
        $manager->checkForLeak('job');
        $logger->shouldHaveReceived('critical')->once();

        $this->assertFalse(
            $manager->hasContext(),
            'leaked context must be cleared before the new unit of work runs',
        );
    }

    /**
     * @throws RlsContextLeaked
     */
    #[Test]
    #[TestDox('Stays silent when the context stack is clean')]
    public function clean_stack_is_silent(): void
    {
        $log = Log::spy();

        app(RlsManager::class)->checkForLeak('job');

        $log->shouldNotHaveReceived('critical');
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('Throw mode raises an exception and still clears the context')]
    public function throw_mode_raises_and_still_clears(): void
    {
        config(['rls.leak_canary' => 'throw']);
        Rls::isolateTo(['tenant_id' => 'leaked']);

        try {
            app(RlsManager::class)->checkForLeak('request');
            $this->fail('Expected RlsContextLeaked to be thrown');
        } catch (RlsContextLeaked) {
            $this->assertFalse(
                Rls::hasContext(),
                'context must be cleared even when throwing',
            );
        }
    }

    /**
     * @throws InvalidContextValue
     * @throws RlsContextLeaked
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('Off mode does nothing with a leaked context')]
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
    #[TestDox('Registers a queue looping listener')]
    public function registers_queue_looping_listener(): void
    {
        $this->assertTrue(Event::hasListeners(Looping::class));
    }
}
