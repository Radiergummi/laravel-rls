<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Report\MarkdownReporter;

use function str_contains;

#[TestDox('Bench MarkdownReporter')]
class MarkdownReporterTest extends TestCase
{
    #[Test]
    #[TestDox('render() produces a table and a headline citing per-query and per-transaction cost')]
    public function renders_table_and_headline(): void
    {
        $document = [
            'env' => ['pg_version' => 'PostgreSQL 18.0'],
            'params' => ['iterations' => 2000],
            'cells' => [
                ['scenario' => 'point_select', 'variant' => 'control', 'scale' => '100k', 'p50_us' => 1.0, 'p99_us' => 2.0],
                ['scenario' => 'point_select', 'variant' => 'treatment', 'scale' => '100k', 'p50_us' => 1.6, 'p99_us' => 3.2],
            ],
            'amortization' => [
                ['scale' => '100k', 'derived_fixed_setconfig_us' => 35.5],
            ],
            'explain' => [],
        ];

        $md = (new MarkdownReporter())->render($document);

        $this->assertStringContainsString('point_select', $md);
        $this->assertStringContainsString('Headline:', $md);
        $this->assertTrue(str_contains($md, 'p50') && str_contains($md, 'p99'));
    }

    #[Test]
    #[TestDox('headline() matches cells by scenario and scale, not array order, across multiple scenarios/scales')]
    public function headline_matches_by_scenario_and_scale(): void
    {
        $document = [
            'env' => ['pg_version' => 'PostgreSQL 18.0'],
            'params' => ['iterations' => 2000, 'scales' => ['1k', '100k']],
            'cells' => [
                ['scenario' => 'range_scan', 'variant' => 'control', 'scale' => '1k', 'p50_us' => 100.0, 'p99_us' => 200.0],
                ['scenario' => 'range_scan', 'variant' => 'treatment', 'scale' => '1k', 'p50_us' => 900.0, 'p99_us' => 950.0],
                ['scenario' => 'point_select', 'variant' => 'control', 'scale' => '1k', 'p50_us' => 500.0, 'p99_us' => 600.0],
                ['scenario' => 'point_select', 'variant' => 'treatment', 'scale' => '1k', 'p50_us' => 800.0, 'p99_us' => 850.0],
                ['scenario' => 'point_select', 'variant' => 'control', 'scale' => '100k', 'p50_us' => 1.0, 'p99_us' => 2.0],
                ['scenario' => 'point_select', 'variant' => 'treatment', 'scale' => '100k', 'p50_us' => 1.6, 'p99_us' => 3.2],
            ],
            'amortization' => [
                ['scale' => '1k', 'derived_fixed_setconfig_us' => 999.0],
                ['scale' => '100k', 'derived_fixed_setconfig_us' => 35.5],
            ],
            'explain' => [],
        ];

        $headline = (new MarkdownReporter())->headline($document);

        $this->assertSame(
            'adds ~0.60 us p50 / ~1.20 us p99 per query and ~one round-trip (~35.50 us) per transaction',
            $headline,
        );
    }

    #[Test]
    #[TestDox('headline() renders n/a when the point_select treatment cell for the chosen scale is missing')]
    public function headline_renders_na_when_treatment_cell_missing(): void
    {
        $document = [
            'env' => ['pg_version' => 'PostgreSQL 18.0'],
            'params' => ['iterations' => 2000, 'scales' => ['100k']],
            'cells' => [
                ['scenario' => 'point_select', 'variant' => 'control', 'scale' => '100k', 'p50_us' => 1.0, 'p99_us' => 2.0],
            ],
            'amortization' => [
                ['scale' => '100k', 'derived_fixed_setconfig_us' => 35.5],
            ],
            'explain' => [],
        ];

        $headline = (new MarkdownReporter())->headline($document);

        $this->assertStringContainsString('n/a', $headline);
        $this->assertStringNotContainsString('0.00', $headline);
    }

    #[Test]
    #[TestDox('renders endpoints table, sweep table, and endpoint headline')]
    public function renders_endpoints_and_sweep(): void
    {
        $document = [
            'cells' => [],
            'params' => ['scales' => ['100k']],
            'amortization' => [],
            'endpoints' => [
                [
                    'label' => 'direct·transaction·wrap', 'connection' => 'pgsql',
                    'strategy' => 'transaction', 'boundary' => 'wrap', 'k' => 1, 'status' => 'ok',
                    'control_us' => 10.0, 'treatment_us' => 14.0,
                    'overhead_endpoint_us' => 4.0, 'overhead_per_query_us' => 4.0,
                ],
                [
                    'label' => 'direct·transaction·wrap', 'connection' => 'pgsql',
                    'strategy' => 'transaction', 'boundary' => 'wrap', 'k' => 30, 'status' => 'ok',
                    'control_us' => 300.0, 'treatment_us' => 420.0,
                    'overhead_endpoint_us' => 120.0, 'overhead_per_query_us' => 4.0,
                ],
                [
                    'label' => 'pgbouncer·session', 'connection' => 'pgsql_pgbouncer',
                    'strategy' => 'session', 'boundary' => '—', 'k' => 10, 'status' => 'unsafe',
                    'note' => 'session GUC does not survive PgBouncer transaction pooling',
                ],
            ],
            'latency_sweep' => [
                [
                    'label' => 'direct·transaction·wrap', 'k' => 10, 'injected_ms' => 5, 'jitter_ms' => 1,
                    'control_us' => 50.0, 'treatment_us' => 90.0, 'overhead_endpoint_us' => 40.0,
                ],
            ],
        ];

        $markdown = (new MarkdownReporter())->render($document);

        $this->assertStringContainsString('## Endpoints', $markdown);
        $this->assertStringContainsString('direct·transaction·wrap', $markdown);
        $this->assertStringContainsString('unsafe', $markdown);
        $this->assertStringContainsString('## Latency sweep', $markdown);
        $this->assertStringContainsString('Endpoint headline:', $markdown);
    }
}
