<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit\Bench;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Stats;

#[TestDox('Bench Stats')]
class StatsTest extends TestCase
{
    #[Test]
    #[TestDox('summarize() computes percentiles, mean and stddev in microseconds')]
    public function summarizes_samples(): void
    {
        // 1_000..10_000 ns => 1..10 us
        $samples = [];

        for ($i = 1; $i <= 10; $i++) {
            $samples[] = $i * 1000;
        }

        $s = Stats::summarize($samples);

        $this->assertSame(10, $s['n']);
        $this->assertSame(1.0, $s['min_us']);
        $this->assertSame(10.0, $s['max_us']);
        $this->assertEqualsWithDelta(5.5, $s['mean_us'], 0.0001);
        // nearest-rank: p50 => ceil(0.5*10)=5 => index 4 => 5.0
        $this->assertSame(5.0, $s['p50_us']);
        $this->assertSame(9.0, $s['p90_us']);
        $this->assertSame(10.0, $s['p99_us']);
        $this->assertEqualsWithDelta(3.0277, $s['stddev_us'], 0.001);
    }

    #[Test]
    #[TestDox('summarize() handles a single sample')]
    public function single_sample(): void
    {
        $s = Stats::summarize([4200]);
        $this->assertSame(1, $s['n']);
        $this->assertSame(4.2, $s['p50_us']);
        $this->assertSame(4.2, $s['p99_us']);
        $this->assertSame(0.0, $s['stddev_us']);
    }

    #[Test]
    #[TestDox('summarize() rejects an empty sample set')]
    public function rejects_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Stats::summarize([]);
    }
}
