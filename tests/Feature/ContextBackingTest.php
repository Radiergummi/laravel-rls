<?php

namespace Radiergummi\Rls\Tests\Feature;

use Illuminate\Support\Facades\Context;
use Radiergummi\Rls\Facades\Rls;
use Radiergummi\Rls\Tests\TestCase;

class ContextBackingTest extends TestCase
{
    public function test_context_survives_dehydrate_hydrate_roundtrip(): void
    {
        // This is the exact path a queued job uses: dehydrate at dispatch,
        // hydrate in the worker.
        Rls::actingAs(['tenant_id' => 'ctx-a', 'user_id' => 7]);

        $payload = Context::dehydrate();

        Context::flush();
        $this->assertFalse(Rls::hasContext(), 'context cleared after flush');

        Context::hydrate($payload);

        $this->assertTrue(Rls::hasContext());
        $this->assertSame('ctx-a', Rls::get('tenant_id'));
        $this->assertSame(7, Rls::get('user_id'));
    }

    public function test_bypass_is_stripped_from_the_dehydrated_payload(): void
    {
        // A job dispatched inside a bypass scope must NOT inherit bypass.
        Rls::withoutRls('export', function () use (&$payload) {
            $this->assertTrue(Rls::current()->isBypass());
            $payload = Context::dehydrate();
        });

        Context::flush();
        Context::hydrate($payload);

        $this->assertFalse(Rls::hasContext(), 'bypass context did not propagate');
    }

    public function test_tenant_below_a_bypass_scope_still_propagates(): void
    {
        Rls::actingAs(['tenant_id' => 'ctx-a']);

        Rls::withoutRls('export', function () use (&$payload) {
            $payload = Context::dehydrate();
        });

        Context::flush();
        Context::hydrate($payload);

        $this->assertSame('ctx-a', Rls::get('tenant_id'), 'tenant context beneath bypass propagates');
    }
}
