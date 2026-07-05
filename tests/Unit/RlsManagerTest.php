<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit;

use Illuminate\Events\Dispatcher;
use Illuminate\Log\Context\Repository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Context\RlsManager;
use Radiergummi\LaravelRls\Events\RlsBypassed;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use RuntimeException;

class RlsManagerTest extends TestCase
{
    #[Test]
    public function starts_empty(): void
    {
        $m = $this->manager();
        $this->assertFalse($m->hasContext());
        $this->assertNull($m->current());
        $this->assertSame([], $m->context());
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
        $m = $this->manager();
        $seen = null;
        $result = $m->actingAs(['tenant_id' => '9'], function () use ($m, &$seen) {
            $seen = $m->get('tenant_id');

            return 'ok';
        });
        $this->assertSame('9', $seen);
        $this->assertSame('ok', $result);
        $this->assertFalse($m->hasContext(), 'context popped after callback');
    }

    /**
     * @throws InvalidContextValue
     */
    #[Test]
    public function acting_as_pops_even_on_exception(): void
    {
        $m = $this->manager();

        try {
            $m->actingAs(['tenant_id' => '9'], fn() => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }
        $this->assertFalse($m->hasContext());
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    public function acting_as_imperative_persists(): void
    {
        $m = $this->manager();
        $m->actingAs(['tenant_id' => '9']);
        $this->assertTrue($m->hasContext());
        $this->assertSame('9', $m->get('tenant_id'));
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    public function nested_contexts_stack(): void
    {
        $m = $this->manager();
        $m->actingAs(['tenant_id' => 'outer']);
        $m->actingAs(['tenant_id' => 'inner'], function () use ($m) {
            $this->assertSame('inner', $m->get('tenant_id'));
        });
        $this->assertSame('outer', $m->get('tenant_id'));
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    public function without_rls_is_a_bypass_scope(): void
    {
        $m = $this->manager();
        $m->withoutRls('seeding', function () use ($m) {
            $this->assertTrue($m->current()->isBypass());
            $this->assertSame('seeding', $m->current()->reason());
        });
        $this->assertFalse($m->hasContext());
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    public function set_merges_into_current(): void
    {
        $m = $this->manager();
        $m->actingAs(['tenant_id' => '9']);
        $m->set('user_id', 5);
        $this->assertSame('9', $m->get('tenant_id'));
        $this->assertSame(5, $m->get('user_id'));
    }

    /**
     * @throws RuntimeException
     */
    #[Test]
    public function rejects_value_violating_declared_type(): void
    {
        $m = $this->manager();
        $m->defineContext(fn($c) => $c->uuid('tenant_id'));

        $this->expectException(InvalidContextValue::class);

        $m->actingAs(['tenant_id' => 'not-a-uuid']);
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    public function accepts_value_matching_declared_type(): void
    {
        $m = $this->manager();
        $m->defineContext(fn($c) => $c->uuid('tenant_id'));

        $m->actingAs(['tenant_id' => '11111111-1111-1111-1111-111111111111']);

        $this->assertTrue($m->hasContext());
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    public function undeclared_dimension_is_not_validated(): void
    {
        $m = $this->manager();
        $m->defineContext(fn($c) => $c->uuid('tenant_id'));

        $m->actingAs([
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
            'user_id' => 'anything',
        ]);

        $this->assertSame('anything', $m->get('user_id'));
    }

    /**
     * @throws RuntimeException
     */
    #[Test]
    public function rejects_non_integer_for_integer_dimension(): void
    {
        $m = $this->manager();
        $m->defineContext(fn($c) => $c->integer('org_id'));

        $this->expectException(InvalidContextValue::class);

        $m->actingAs(['org_id' => 'abc']);
    }

    /**
     * @throws RuntimeException
     */
    #[Test]
    public function set_also_validates(): void
    {
        $m = $this->manager();
        $m->defineContext(fn($c) => $c->uuid('tenant_id'));

        $this->expectException(InvalidContextValue::class);

        $m->set('tenant_id', 'nope');
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    public function no_schema_means_no_validation(): void
    {
        $m = $this->manager();

        $m->actingAs(['tenant_id' => 'anything-goes']);

        $this->assertSame('anything-goes', $m->get('tenant_id'));
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

        $m = new RlsManager(new Repository(new Dispatcher()), $events);
        $m->withoutRls('nightly-report', fn() => null);

        $this->assertInstanceOf(RlsBypassed::class, $captured);
        $this->assertSame('nightly-report', $captured->reason);
    }
}
