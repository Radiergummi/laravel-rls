<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Log\Context\Repository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Radiergummi\LaravelRls\Context\ContextSchema;
use Radiergummi\LaravelRls\Context\RlsManager;
use Radiergummi\LaravelRls\Events\RlsBypassed;
use Radiergummi\LaravelRls\Exceptions\AdminConnectionRequired;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use RuntimeException;

#[TestDox('RLS Manager')]
class RlsManagerTest extends TestCase
{
    #[Test]
    #[TestDox('A fresh manager has no context, current(), or values')]
    public function starts_empty(): void
    {
        $manager = $this->manager();
        $this->assertFalse($manager->hasContext());
        $this->assertNull($manager->current());
        $this->assertSame([], $manager->context());
    }

    private function manager(?Dispatcher $events = null): RlsManager
    {
        // A container holding a fixed Context repository: the manager resolves the
        // repository through the container (so it tracks scope resets in a real
        // app), which here just returns this one instance for the whole test.
        $container = new Container();
        $container->instance(Repository::class, new Repository(new Dispatcher()));

        return new RlsManager($container, new NullLogger(), $events);
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('isolateTo() with a callback pushes context and pops it after execution')]
    public function acting_as_scoped_pushes_and_pops(): void
    {
        $manager = $this->manager();
        $seen = null;
        $result = $manager->isolateTo(['tenant_id' => '9'], function () use ($manager, &$seen) {
            $seen = $manager->get('tenant_id');

            return 'ok';
        });
        $this->assertSame('9', $seen);
        $this->assertSame('ok', $result);
        $this->assertFalse($manager->hasContext(), 'context popped after callback');
    }

    /**
     * @throws InvalidContextValue
     */
    #[Test]
    #[TestDox('isolateTo() pops context even when the callback throws')]
    public function acting_as_pops_even_on_exception(): void
    {
        $manager = $this->manager();

        try {
            $manager->isolateTo(
                ['tenant_id' => '9'],
                fn() => throw new RuntimeException('boom'),
            );
        } catch (RuntimeException) {
        }
        $this->assertFalse($manager->hasContext());
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('isolateTo() without a callback persists the context')]
    public function acting_as_imperative_persists(): void
    {
        $manager = $this->manager();
        $manager->isolateTo(['tenant_id' => '9']);
        $this->assertTrue($manager->hasContext());
        $this->assertSame('9', $manager->get('tenant_id'));
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('Nested isolateTo() calls stack, restoring the outer context after the inner one')]
    public function nested_contexts_stack(): void
    {
        $manager = $this->manager();
        $manager->isolateTo(['tenant_id' => 'outer']);
        $manager->isolateTo(['tenant_id' => 'inner'], function () use ($manager) {
            $this->assertSame('inner', $manager->get('tenant_id'));
        });
        $this->assertSame('outer', $manager->get('tenant_id'));
    }

    #[Test]
    #[TestDox('withoutIsolation() hard-fails when no bypass handler is installed')]
    public function without_rls_requires_a_handler(): void
    {
        $manager = $this->manager();

        $this->expectException(AdminConnectionRequired::class);

        $manager->withoutIsolation('seeding', fn() => null);
    }

    /**
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('withoutIsolation() routes to the handler with isBypassing() set for its duration')]
    public function without_rls_routes_to_the_handler(): void
    {
        $manager = $this->manager();
        $manager->setBypassHandler(static fn(string $reason, callable $callback) => $callback());

        $this->assertFalse($manager->isBypassing());

        // The callback returns what it observed; withoutIsolation() returns mixed, so that
        // observation propagates out as the return value (and proves the callback was routed).
        $during = $manager->withoutIsolation('seeding', fn() => $manager->isBypassing());

        $this->assertTrue($during, 'isBypassing() is set for the callback duration');
        $this->assertFalse($manager->isBypassing(), 'isBypassing() clears after the callback');
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('set() merges a value into the current context')]
    public function set_merges_into_current(): void
    {
        $manager = $this->manager();
        $manager->isolateTo(['tenant_id' => '9']);
        $manager->set('user_id', 5);
        $this->assertSame('9', $manager->get('tenant_id'));
        $this->assertSame(5, $manager->get('user_id'));
    }

    /**
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('isolateTo() rejects a value that violates the declared context schema')]
    public function rejects_value_violating_declared_type(): void
    {
        $manager = $this->manager();
        $manager->defineContext(fn(ContextSchema $context) => $context->uuid('tenant_id'));

        $this->expectException(InvalidContextValue::class);

        $manager->isolateTo(['tenant_id' => 'not-a-uuid']);
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('isolateTo() accepts a value matching the declared context schema')]
    public function accepts_value_matching_declared_type(): void
    {
        $manager = $this->manager();
        $manager->defineContext(fn(ContextSchema $context) => $context->uuid('tenant_id'));

        $manager->isolateTo(['tenant_id' => '11111111-1111-1111-1111-111111111111']);

        $this->assertTrue($manager->hasContext());
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('An isolation key without a declared schema is not validated')]
    public function undeclared_key_is_not_validated(): void
    {
        $manager = $this->manager();
        $manager->defineContext(fn(ContextSchema $context) => $context->uuid('tenant_id'));

        $manager->isolateTo([
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
            'user_id' => 'anything',
        ]);

        $this->assertSame('anything', $manager->get('user_id'));
    }

    /**
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('isolateTo() rejects a non-integer value for an integer isolation key')]
    public function rejects_non_integer_for_integer_key(): void
    {
        $manager = $this->manager();
        $manager->defineContext(fn(ContextSchema $context) => $context->integer('org_id'));

        $this->expectException(InvalidContextValue::class);

        $manager->isolateTo(['org_id' => 'abc']);
    }

    /**
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('set() also validates against the declared context schema')]
    public function set_also_validates(): void
    {
        $manager = $this->manager();
        $manager->defineContext(fn(ContextSchema $context) => $context->uuid('tenant_id'));

        $this->expectException(InvalidContextValue::class);

        $manager->set('tenant_id', 'nope');
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('Without a declared context schema, isolateTo() performs no validation')]
    public function no_schema_means_no_validation(): void
    {
        $manager = $this->manager();

        $manager->isolateTo(['tenant_id' => 'anything-goes']);

        $this->assertSame('anything-goes', $manager->get('tenant_id'));
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('withoutIsolation() dispatches an RlsBypassed event carrying the reason')]
    public function without_rls_dispatches_a_bypass_event_with_the_reason(): void
    {
        $events = new Dispatcher();
        $captured = null;
        $events->listen(
            RlsBypassed::class,
            function ($event) use (&$captured) {
                $captured = $event;
            },
        );

        $manager = $this->manager($events);
        $manager->setBypassHandler(static fn(string $reason, callable $callback) => $callback());
        $manager->withoutIsolation('nightly-report', fn() => null);

        $this->assertInstanceOf(RlsBypassed::class, $captured);
        $this->assertSame('nightly-report', $captured->reason);
    }
}
