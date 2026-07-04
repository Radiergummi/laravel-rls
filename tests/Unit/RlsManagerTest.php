<?php

namespace Radiergummi\Rls\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Radiergummi\Rls\Context\RlsManager;

class RlsManagerTest extends TestCase
{
    public function test_starts_empty(): void
    {
        $m = new RlsManager();
        $this->assertFalse($m->hasContext());
        $this->assertNull($m->current());
        $this->assertSame([], $m->context());
    }

    public function test_acting_as_scoped_pushes_and_pops(): void
    {
        $m = new RlsManager();
        $seen = null;
        $result = $m->actingAs(['tenant_id' => '9'], function () use ($m, &$seen) {
            $seen = $m->get('tenant_id');
            return 'ok';
        });
        $this->assertSame('9', $seen);
        $this->assertSame('ok', $result);
        $this->assertFalse($m->hasContext(), 'context popped after callback');
    }

    public function test_acting_as_pops_even_on_exception(): void
    {
        $m = new RlsManager();
        try {
            $m->actingAs(['tenant_id' => '9'], fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }
        $this->assertFalse($m->hasContext());
    }

    public function test_acting_as_imperative_persists(): void
    {
        $m = new RlsManager();
        $m->actingAs(['tenant_id' => '9']);
        $this->assertTrue($m->hasContext());
        $this->assertSame('9', $m->get('tenant_id'));
    }

    public function test_nested_contexts_stack(): void
    {
        $m = new RlsManager();
        $m->actingAs(['tenant_id' => 'outer']);
        $m->actingAs(['tenant_id' => 'inner'], function () use ($m) {
            $this->assertSame('inner', $m->get('tenant_id'));
        });
        $this->assertSame('outer', $m->get('tenant_id'));
    }

    public function test_without_rls_is_a_bypass_scope(): void
    {
        $m = new RlsManager();
        $m->withoutRls('seeding', function () use ($m) {
            $this->assertTrue($m->current()->isBypass());
            $this->assertSame('seeding', $m->current()->reason());
        });
        $this->assertFalse($m->hasContext());
    }

    public function test_set_merges_into_current(): void
    {
        $m = new RlsManager();
        $m->actingAs(['tenant_id' => '9']);
        $m->set('user_id', 5);
        $this->assertSame('9', $m->get('tenant_id'));
        $this->assertSame(5, $m->get('user_id'));
    }
}
