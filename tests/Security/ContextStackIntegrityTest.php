<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Context\ContextSchema;
use Radiergummi\LaravelRls\Context\RlsManager;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Facades\Rls;
use RuntimeException;

use function app;
use function assert;

/**
 * Threat category 1 (the infrastructure-free subset) — context-stack integrity.
 * Nested isolateTo/withoutIsolation frames must restore correctly on every exit
 * path, including exceptions and deep nesting, so one unit of work's scope can
 * never bleed into another on the same worker. The cross-worker cases (pooling
 * interleave, Octane, queue retry) live in CrossWorkerLeakageTest.
 *
 * @see docs/MILESTONES.md — Milestone B, threat category 1
 */
#[TestDox('Security: context stack integrity')]
class ContextStackIntegrityTest extends SecurityTestCase
{
    #[Test]
    #[TestDox('An exception inside a nested isolateTo restores the enclosing scope')]
    public function exception_restores_the_enclosing_scope(): void
    {
        $this->isolateTo(['tenant_id' => $this->tenantA->id], function (): void {
            try {
                $this->isolateTo(['tenant_id' => $this->tenantB->id], function (): void {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException) {
                // expected
            }

            $this->assertSame(
                $this->tenantA->id,
                Rls::get('tenant_id'),
                'The inner frame was not popped after the exception — B leaked into A.',
            );
        });

        $this->assertFalse(Rls::hasContext(), 'The outer frame leaked past its callback.');
    }

    #[Test]
    #[TestDox('Deep nesting unwinds one frame at a time back to the base')]
    public function deep_nesting_unwinds_cleanly(): void
    {
        $outer = $this->tenantA->id;
        $middle = $this->tenantB->id;
        $inner = $this->tenantA->id;

        $this->isolateTo(['tenant_id' => $outer], function () use ($outer, $middle, $inner): void {
            $this->isolateTo(['tenant_id' => $middle], function () use ($middle, $inner): void {
                $this->isolateTo(['tenant_id' => $inner], function () use ($inner): void {
                    $this->assertSame($inner, Rls::get('tenant_id'));
                });

                $this->assertSame($middle, Rls::get('tenant_id'));
            });

            $this->assertSame($outer, Rls::get('tenant_id'));
        });

        $this->assertFalse(Rls::hasContext());
    }

    #[Test]
    #[TestDox('A system() bypass nested in isolateTo does not disturb the isolation stack')]
    public function bypass_does_not_disturb_the_stack(): void
    {
        $this->isolateTo(['tenant_id' => $this->tenantA->id], function (): void {
            Rls::system('audit', fn() => null);

            $this->assertSame(
                $this->tenantA->id,
                Rls::get('tenant_id'),
                'system() mutated the context stack — it must route to the admin connection instead.',
            );
        });
    }

    #[Test]
    #[TestDox('A validation failure in set() does not drop the current frame')]
    public function a_failed_set_keeps_the_current_frame(): void
    {
        Rls::defineContext(fn(ContextSchema $schema) => $schema->uuid('tenant_id'));

        $manager = app(RlsManager::class);
        assert($manager instanceof RlsManager);

        $this->isolateTo(['tenant_id' => $this->tenantA->id], function () use ($manager): void {
            try {
                $manager->set('tenant_id', 'not-a-uuid');

                $this->fail('Expected InvalidContextValue');
            } catch (InvalidContextValue) {
                // expected
            }

            $this->assertSame(
                $this->tenantA->id,
                Rls::get('tenant_id'),
                'A rejected set() dropped the current frame, exposing whatever sat beneath it.',
            );
        });
    }

    #[Test]
    #[TestDox('pop() on an empty stack is a safe no-op')]
    public function pop_on_an_empty_stack_is_safe(): void
    {
        Rls::forget();

        Rls::pop();

        $this->assertFalse(Rls::hasContext());
    }
}
