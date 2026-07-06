<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Exceptions\MissingIsolationContext;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\Fixtures\Models\Document;
use Radiergummi\LaravelRls\Tests\TestCase;
use RuntimeException;

#[TestDox('Fail-loud guard')]
class FailLoudGuardTest extends TestCase
{
    #[Test]
    #[TestDox('Query on managed table without isolation context throws')]
    public function query_on_managed_table_without_context_throws(): void
    {
        $this->expectException(MissingIsolationContext::class);

        Document::query()->count();
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('Query with isolation context does not throw')]
    public function query_with_context_does_not_throw(): void
    {
        Rls::isolateTo(
            ['tenant_id' => '11111111-1111-1111-1111-111111111111'],
            fn() => $this->assertSame(0, Document::query()->count()),
        );
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('Query inside Bypass does not throw')]
    public function query_inside_bypass_does_not_throw(): void
    {
        Rls::withoutIsolation(
            'maintenance',
            fn() => $this->assertSame(0, Document::query()->count()),
        );
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        config(['rls.on_missing_context' => 'throw']);

        // The bypass test runs a real read inside withoutIsolation(), which now routes to a
        // privileged admin connection (a BYPASSRLS role). The guard stands down for its duration via
        // the in-flight bypass flag, so the query must not throw MissingIsolationContext.
        $this->useBypassAdminConnection($app);
    }
}
