<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Toxiproxy;

#[TestDox('Bench Toxiproxy')]
class ToxiproxyTest extends TestCase
{
    #[Test]
    #[TestDox('payload() builds a downstream latency toxic')]
    public function payload_builds_a_latency_toxic(): void
    {
        $payload = (new Toxiproxy())->payload(5, 1);

        $this->assertSame('latency_downstream', $payload['name']);
        $this->assertSame('latency', $payload['type']);
        $this->assertSame('downstream', $payload['stream']);
        $this->assertSame(5, $payload['attributes']['latency']);
        $this->assertSame(1, $payload['attributes']['jitter']);
    }
}
