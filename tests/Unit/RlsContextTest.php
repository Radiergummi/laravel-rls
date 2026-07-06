<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Context\RlsContext;

#[TestDox('RLS Context')]
class RlsContextTest extends TestCase
{
    #[Test]
    #[TestDox('make() stores values retrievable via get() and has()')]
    public function holds_and_reads_values(): void
    {
        $context = RlsContext::make(['tenant_id' => 'abc', 'user_id' => 7]);
        $this->assertSame('abc', $context->get('tenant_id'));
        $this->assertSame(7, $context->get('user_id'));
        $this->assertTrue($context->has('tenant_id'));
        $this->assertFalse($context->has('missing'));
        $this->assertNull($context->get('missing'));
    }

    #[Test]
    #[TestDox('with() returns a new instance without mutating the original')]
    public function with_returns_new_instance_without_mutating(): void
    {
        $a = RlsContext::make(['tenant_id' => '1']);
        $b = $a->with(['tenant_id' => '2', 'x' => 'y']);
        $this->assertSame('1', $a->get('tenant_id'));
        $this->assertSame('2', $b->get('tenant_id'));
        $this->assertSame('y', $b->get('x'));
        $this->assertNotSame($a, $b);
    }
}
