<?php

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Radiergummi\LaravelRls\Events\RlsBypassed;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;

class BypassObservabilityTest extends TestCase
{
    public function test_bypass_dispatches_the_event_in_a_booted_app(): void
    {
        $captured = null;
        Event::listen(RlsBypassed::class, function ($event) use (&$captured) {
            $captured = $event;
        });

        Rls::withoutRls('data-migration', fn () => null);

        $this->assertInstanceOf(RlsBypassed::class, $captured);
        $this->assertSame('data-migration', $captured->reason);
    }

    public function test_bypass_is_logged_with_its_reason(): void
    {
        Log::spy();

        Rls::withoutRls('seeding', fn () => null);

        Log::shouldHaveReceived('notice')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'seeding')
                || ($context['reason'] ?? null) === 'seeding')
            ->once();
    }
}
