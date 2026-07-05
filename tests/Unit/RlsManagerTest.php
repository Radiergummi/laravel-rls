<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit;

use Illuminate\Events\Dispatcher;
use Illuminate\Log\Context\Repository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Context\ContextSchema;
use Radiergummi\LaravelRls\Context\RlsManager;
use Radiergummi\LaravelRls\Events\RlsBypassed;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use RuntimeException;

class RlsManagerTest extends TestCase
{
    #[Test]
    public function starts_empty(): void
    {
        $manager = $this->manager();
        $this->assertFalse($manager->hasContext());
        $this->assertNull($manager->current());
        $this->assertSame([], $manager->context());
    }

    private function manager(): RlsManager
    {
        return new RlsManager(new Repository(new Dispatcher()));
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
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
    public function nested_contexts_stack(): void
    {
        $manager = $this->manager();
        $manager->isolateTo(['tenant_id' => 'outer']);
        $manager->isolateTo(['tenant_id' => 'inner'], function () use ($manager) {
            $this->assertSame('inner', $manager->get('tenant_id'));
        });
        $this->assertSame('outer', $manager->get('tenant_id'));
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    public function without_rls_is_a_bypass_scope(): void
    {
        $manager = $this->manager();
        $manager->withoutRls('seeding', function () use ($manager) {
            $this->assertTrue($manager->current()?->isBypass());
            $this->assertSame('seeding', $manager->current()->reason());
        });
        $this->assertFalse($manager->hasContext());
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
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
    public function accepts_value_matching_declared_type(): void
    {
        $manager = $this->manager();
        $manager->defineContext(fn($c) => $c->uuid('tenant_id'));

        $manager->isolateTo(['tenant_id' => '11111111-1111-1111-1111-111111111111']);

        $this->assertTrue($manager->hasContext());
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    public function undeclared_dimension_is_not_validated(): void
    {
        $manager = $this->manager();
        $manager->defineContext(fn($c) => $c->uuid('tenant_id'));

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
    public function rejects_non_integer_for_integer_dimension(): void
    {
        $manager = $this->manager();
        $manager->defineContext(fn($c) => $c->integer('org_id'));

        $this->expectException(InvalidContextValue::class);

        $manager->isolateTo(['org_id' => 'abc']);
    }

    /**
     * @throws RuntimeException
     */
    #[Test]
    public function set_also_validates(): void
    {
        $manager = $this->manager();
        $manager->defineContext(fn($c) => $c->uuid('tenant_id'));

        $this->expectException(InvalidContextValue::class);

        $manager->set('tenant_id', 'nope');
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
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

        $manager = new RlsManager(new Repository(new Dispatcher()), $events);
        $manager->withoutRls('nightly-report', fn() => null);

        $this->assertInstanceOf(RlsBypassed::class, $captured);
        $this->assertSame('nightly-report', $captured->reason);
    }
}
