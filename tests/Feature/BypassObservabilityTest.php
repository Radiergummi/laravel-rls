<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\LoggerInterface;
use Radiergummi\LaravelRls\Events\RlsBypassed;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;
use RuntimeException;

#[TestDox('Bypass Observability')]
class BypassObservabilityTest extends TestCase
{
    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('withoutIsolation() dispatches the RlsBypassed event in a booted app')]
    public function bypass_dispatches_the_event_in_a_booted_app(): void
    {
        $captured = null;
        Event::listen(
            RlsBypassed::class,
            static function (RlsBypassed $event) use (&$captured): void {
                $captured = $event;
            },
        );

        Rls::withoutIsolation('data-migration', static fn() => null);

        $this->assertInstanceOf(RlsBypassed::class, $captured);
        $this->assertSame('data-migration', $captured->reason);
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('withoutIsolation() logs the bypass with its reason')]
    public function bypass_is_logged_with_its_reason(): void
    {
        $logger = Mockery::spy(LoggerInterface::class);
        $logManager = $this->app->make(LogManager::class);

        // extend() only fires for a channel whose configured driver matches the extension name, so
        // the 'rls' channel must exist in config or channel('rls') falls back to the emergency
        // logger and the spy is never consulted.
        config(['logging.channels.rls' => ['driver' => 'rls']]);
        $logManager->forgetChannel('rls');
        $logManager->extend('rls', fn() => $logger);

        Rls::withoutIsolation('seeding', static fn() => null);

        $logger
            ->shouldHaveReceived('warning')
            ->withArgs(fn(string $message, array $context = [])
                => str_contains($message, 'seeding')
                || ($context['reason'] ?? null) === 'seeding')
            ->once();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Bypass now routes to an admin connection; the callbacks here do no DB work, but
        // withoutIsolation() still requires a configured admin_connection to run at all.
        $this->useBypassAdminConnection($app);
    }
}
