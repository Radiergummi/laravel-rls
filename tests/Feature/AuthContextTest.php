<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\GenericUser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;

#[TestDox("RLS works with Laravel's Auth context")]
class AuthContextTest extends TestCase
{
    #[Test]
    #[TestDox('Context is established from the authenticated user')]
    public function context_is_established_from_the_authenticated_user(): void
    {
        Rls::resolveContextUsing(
            static fn(mixed $user): array
                // @phpstan-ignore property.notFound (tenant_id is a property of GenericUser)
                => $user instanceof GenericUser ? ['tenant_id' => $user->tenant_id] : [],
        );

        $user = new GenericUser(['id' => 1, 'tenant_id' => 'ten-99']);
        event(new Authenticated('web', $user));

        $this->assertTrue(Rls::hasContext());
        $this->assertSame('ten-99', Rls::get('tenant_id'));
    }

    #[Test]
    #[TestDox('Resolver yielding nothing leaves context empty')]
    public function resolver_yielding_nothing_leaves_context_empty(): void
    {
        Rls::resolveContextUsing(
            static fn(mixed $user): array
                // @phpstan-ignore property.notFound (tenant_id is a property of GenericUser)
                => $user instanceof GenericUser && $user->tenant_id
                ? ['tenant_id' => $user->tenant_id]
                : [],
        );

        $user = new GenericUser(['id' => 1, 'tenant_id' => null]);
        event(new Authenticated('web', $user));

        $this->assertFalse(Rls::hasContext());
    }
}
