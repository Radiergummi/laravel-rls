<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\GenericUser;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;

class AuthContextTest extends TestCase
{
    public function test_context_is_established_from_the_authenticated_user(): void
    {
        Rls::resolveContextUsing(fn($user) => ['tenant_id' => $user->tenant_id]);

        $user = new GenericUser(['id' => 1, 'tenant_id' => 'ten-99']);
        event(new Authenticated('web', $user));

        $this->assertTrue(Rls::hasContext());
        $this->assertSame('ten-99', Rls::get('tenant_id'));
    }

    public function test_resolver_yielding_nothing_leaves_context_empty(): void
    {
        Rls::resolveContextUsing(fn($user) => $user->tenant_id ? ['tenant_id' => $user->tenant_id] : []);

        $user = new GenericUser(['id' => 1, 'tenant_id' => null]);
        event(new Authenticated('web', $user));

        $this->assertFalse(Rls::hasContext());
    }
}
