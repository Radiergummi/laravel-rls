<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\Context;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;
use RuntimeException;

#[TestDox('Context backing')]
class ContextBackingTest extends TestCase
{
    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('Context survives dehydrate/hydrate roundtrip')]
    public function context_survives_dehydrate_hydrate_roundtrip(): void
    {
        // This is the exact path a queued job uses: dehydrate at dispatch,
        // hydrate in the worker.
        Rls::isolateTo(['tenant_id' => 'ctx-a', 'user_id' => 7]);

        /** @noinspection PhpInternalEntityUsedInspection */
        $payload = Context::dehydrate();

        Context::flush();
        $this->assertFalse(Rls::hasContext(), 'context cleared after flush');

        /** @noinspection PhpInternalEntityUsedInspection */
        Context::hydrate($payload);

        $this->assertTrue(Rls::hasContext());
        $this->assertSame('ctx-a', Rls::get('tenant_id'));
        $this->assertSame(7, Rls::get('user_id'));
    }
}
