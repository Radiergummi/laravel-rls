<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Env;
use Radiergummi\LaravelRls\Bench\Report\JsonReporter;

use function json_decode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[TestDox('Bench JsonReporter')]
class JsonReporterTest extends TestCase
{
    #[Test]
    #[TestDox('render() assembles an env-stamped baseline document')]
    public function renders_document(): void
    {
        $env = Env::describe('PostgreSQL 18.0', 'abc123', '2026-07-06T00:00:00Z', false, false);
        $doc = (new JsonReporter())->render(
            $env,
            ['iterations' => 2000, 'warmup' => 200, 'scales' => ['1k', '100k']],
            [['scenario' => 'point_select', 'variant' => 'treatment', 'scale' => '1k', 'n' => 2000, 'p50_us' => 1.2]],
            [['scale' => '1k', 'per_txn_1_query_us' => 40.0, 'per_txn_10_query_us' => 4.5, 'derived_fixed_setconfig_us' => 35.5]],
            [['scenario' => 'range_scan', 'scale' => '100k', 'scan_type' => 'Bitmap Heap Scan', 'parallel' => false, 'exec_ms' => 0.7]],
        );

        $this->assertSame('abc123', $doc['env']['git_commit']);
        $this->assertSame(PHP_VERSION, $doc['env']['php_version']);
        $this->assertSame(2000, $doc['params']['iterations']);
        $this->assertSame('point_select', $doc['cells'][0]['scenario']);
        $this->assertSame('Bitmap Heap Scan', $doc['explain'][0]['scan_type']);
        $this->assertSame(35.5, $doc['amortization'][0]['derived_fixed_setconfig_us']);
    }

    #[Test]
    #[TestDox('write() emits valid JSON to disk')]
    public function writes_json(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bench');
        $reporter = new JsonReporter();
        $doc = $reporter->render(
            Env::describe('PostgreSQL 18.0', 'abc123', '2026-07-06T00:00:00Z', false, false),
            ['iterations' => 5, 'warmup' => 2, 'scales' => ['1k']],
            [],
            [],
            [],
        );

        $reporter->write($path, $doc);
        $roundTrip = json_decode((string) file_get_contents($path), true);

        $this->assertSame('abc123', $roundTrip['env']['git_commit']);
        unlink($path);
    }
}
