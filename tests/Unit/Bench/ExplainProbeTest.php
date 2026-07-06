<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\ExplainProbe;

#[TestDox('Bench ExplainProbe parser')]
class ExplainProbeTest extends TestCase
{
    #[Test]
    #[TestDox('parse() reports a bitmap index scan as index-backed and non-parallel')]
    public function parses_bitmap_index_scan(): void
    {
        $explain = [
            'Plan' => [
                'Node Type' => 'Bitmap Heap Scan',
                'Relation Name' => 'bench_treatment',
                'Plans' => [
                    ['Node Type' => 'Bitmap Index Scan', 'Index Name' => 'bt_tenant_idx'],
                ],
            ],
            'Execution Time' => 0.734,
        ];

        $result = ExplainProbe::parse($explain);

        $this->assertSame('Bitmap Heap Scan', $result['scan_type']);
        $this->assertFalse($result['parallel']);
        $this->assertSame(0.734, $result['exec_ms']);
        $this->assertStringNotContainsStringIgnoringCase('seq', $result['scan_type']);
    }

    #[Test]
    #[TestDox('parse() reports a sequential scan')]
    public function parses_seq_scan(): void
    {
        $explain = [
            'Plan' => [
                'Node Type' => 'Aggregate',
                'Plans' => [
                    ['Node Type' => 'Seq Scan', 'Relation Name' => 'bench_floor'],
                ],
            ],
            'Execution Time' => 26.6,
        ];

        $result = ExplainProbe::parse($explain);

        $this->assertSame('Seq Scan', $result['scan_type']);
        $this->assertFalse($result['parallel']);
        $this->assertSame(26.6, $result['exec_ms']);
    }

    #[Test]
    #[TestDox('parse() detects a parallel plan via a Gather node')]
    public function detects_parallel(): void
    {
        $explain = [
            'Plan' => [
                'Node Type' => 'Gather',
                'Plans' => [
                    ['Node Type' => 'Parallel Seq Scan', 'Parallel Aware' => true],
                ],
            ],
            'Execution Time' => 5.0,
        ];

        $result = ExplainProbe::parse($explain);

        $this->assertTrue($result['parallel']);
        $this->assertSame('Parallel Seq Scan', $result['scan_type']);
    }
}
