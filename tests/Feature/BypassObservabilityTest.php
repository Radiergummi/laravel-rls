<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Radiergummi\LaravelRls\Events\RlsBypassed;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;

class BypassObservabilityTest extends TestCase
{
    #[Test]
    public function bypass_dispatches_the_event_in_a_booted_app(): void
    {
        $captured = null;
        Event::listen(RlsBypassed::class, function ($event) use (&$captured) {
            $captured = $event;
        });

        Rls::withoutIsolation('data-migration', fn() => null);

        $this->assertInstanceOf(RlsBypassed::class, $captured);
        $this->assertSame('data-migration', $captured->reason);
    }

    #[Test]
    public function bypass_is_logged_with_its_reason(): void
    {
        $log = Log::spy();

        Rls::withoutIsolation('seeding', fn() => null);

        $log
            ->shouldHaveReceived('notice')
            ->withArgs(fn($message, $context = [])
                => str_contains($message, 'seeding')
                || ($context['reason'] ?? null) === 'seeding')
            ->once();
    }
}
