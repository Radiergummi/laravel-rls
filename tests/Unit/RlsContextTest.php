<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Context\RlsContext;

class RlsContextTest extends TestCase
{
    #[Test]
    public function holds_and_reads_values(): void
    {
        $c = RlsContext::make(['tenant_id' => 'abc', 'user_id' => 7]);
        $this->assertSame('abc', $c->get('tenant_id'));
        $this->assertSame(7, $c->get('user_id'));
        $this->assertTrue($c->has('tenant_id'));
        $this->assertFalse($c->has('missing'));
        $this->assertNull($c->get('missing'));
        $this->assertFalse($c->isBypass());
    }

    #[Test]
    public function with_returns_new_instance_without_mutating(): void
    {
        $a = RlsContext::make(['tenant_id' => '1']);
        $b = $a->with(['tenant_id' => '2', 'x' => 'y']);
        $this->assertSame('1', $a->get('tenant_id'));
        $this->assertSame('2', $b->get('tenant_id'));
        $this->assertSame('y', $b->get('x'));
        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function bypass_context(): void
    {
        $c = RlsContext::bypass('nightly-export');
        $this->assertTrue($c->isBypass());
        $this->assertSame('nightly-export', $c->reason());
        $this->assertSame([], $c->values());
    }
}
