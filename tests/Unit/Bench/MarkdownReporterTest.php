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
}
