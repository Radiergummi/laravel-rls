<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Runner;
use Radiergummi\LaravelRls\Bench\Variant;

#[TestDox('Bench Runner')]
class RunnerTest extends TestCase
{
    #[Test]
    #[TestDox('measure() returns one positive ns sample per measured iteration, excluding warmup')]
    public function measures_iterations(): void
    {
        $calls = 0;
        $operation = function (Variant $variant) use (&$calls): void {
            $calls++;
        };

        $samples = (new Runner())->measure($operation, Variant::Treatment, warmup: 3, iterations: 5);

        $this->assertCount(5, $samples);
        $this->assertSame(8, $calls, 'warmup (3) + measured (5) invocations');

        foreach ($samples as $ns) {
            $this->assertIsInt($ns);
            $this->assertGreaterThanOrEqual(0, $ns);
        }
    }

    #[Test]
    #[TestDox('measure() passes the variant through to the operation')]
    public function passes_variant(): void
    {
        $seen = null;
        (new Runner())->measure(
            function (Variant $variant) use (&$seen): void {
                $seen = $variant;
            },
            Variant::Control,
            warmup: 0,
            iterations: 1,
        );

        $this->assertSame(Variant::Control, $seen);
    }
}
