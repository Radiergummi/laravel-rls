<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\Context;
use PHPUnit\Framework\Attributes\Test;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;

class ContextBackingTest extends TestCase
{
    #[Test]
    public function context_survives_dehydrate_hydrate_roundtrip(): void
    {
        // This is the exact path a queued job uses: dehydrate at dispatch,
        // hydrate in the worker.
        Rls::isolateTo(['tenant_id' => 'ctx-a', 'user_id' => 7]);

        $payload = Context::dehydrate();

        Context::flush();
        $this->assertFalse(Rls::hasContext(), 'context cleared after flush');

        Context::hydrate($payload);

        $this->assertTrue(Rls::hasContext());
        $this->assertSame('ctx-a', Rls::get('tenant_id'));
        $this->assertSame(7, Rls::get('user_id'));
    }

    #[Test]
    public function bypass_is_stripped_from_the_dehydrated_payload(): void
    {
        // A job dispatched inside a bypass scope must NOT inherit bypass.
        Rls::withoutIsolation('export', function () use (&$payload) {
            $this->assertTrue(Rls::current()?->isBypass());
            $payload = Context::dehydrate();
        });

        Context::flush();
        Context::hydrate($payload);

        $this->assertFalse(Rls::hasContext(), 'bypass context did not propagate');
    }

    #[Test]
    public function tenant_below_a_bypass_scope_still_propagates(): void
    {
        Rls::isolateTo(['tenant_id' => 'ctx-a']);

        Rls::withoutIsolation('export', function () use (&$payload) {
            $payload = Context::dehydrate();
        });

        Context::flush();
        Context::hydrate($payload);

        $this->assertSame('ctx-a', Rls::get('tenant_id'), 'tenant context beneath bypass propagates');
    }
}
