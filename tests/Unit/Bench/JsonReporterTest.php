<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit\Bench;

use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\BenchmarkEnvironment;
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
        $env = BenchmarkEnvironment::describe(
            'PostgreSQL 18.0',
            'abc123',
            '2026-07-06T00:00:00Z',
            false,
            false,
        );

        $document = (new JsonReporter())->render(
            $env,
            [
                'iterations' => 2000,
                'warmup' => 200,
                'scales' => ['1k', '100k'],
            ],
            [
                [
                    'scenario' => 'point_select',
                    'variant' => 'treatment',
                    'scale' => '1k',
                    'n' => 2000,
                    'p50_us' => 1.2,
                ],
            ],
            [
                [
                    'scale' => '1k',
                    'per_txn_1_query_us' => 40.0,
                    'per_txn_10_query_us' => 4.5,
                    'derived_fixed_setconfig_us' => 35.5,
                ],
            ],
            [
                [
                    'scenario' => 'range_scan',
                    'scale' => '100k',
                    'scan_type' => 'Bitmap Heap Scan',
                    'parallel' => false,
                    'exec_ms' => 0.7,
                ],
            ],
        );

        $this->assertSame('abc123', $document['env']['git_commit']);
        $this->assertSame(PHP_VERSION, $document['env']['php_version']);
        $this->assertSame(2000, $document['params']['iterations']);
        $this->assertSame('point_select', $document['cells'][0]['scenario']);
        $this->assertSame('Bitmap Heap Scan', $document['explain'][0]['scan_type']);
        $this->assertSame(35.5, $document['amortization'][0]['derived_fixed_setconfig_us']);
    }

    /**
     * @throws JsonException
     */
    #[Test]
    #[TestDox('write() emits valid JSON to disk')]
    public function writes_json(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bench');
        $reporter = new JsonReporter();
        $document = $reporter->render(
            BenchmarkEnvironment::describe(
                'PostgreSQL 18.0',
                'abc123',
                '2026-07-06T00:00:00Z',
                false,
                false,
            ),
            ['iterations' => 5, 'warmup' => 2, 'scales' => ['1k']],
            [],
            [],
            [],
        );

        $reporter->write($path, $document);
        $roundTrip = json_decode(
            (string) file_get_contents($path),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($roundTrip);
        $this->assertArrayHasKey('env', $roundTrip);
        $this->assertIsArray($roundTrip['env']);
        $this->assertArrayHasKey('git_commit', $roundTrip['env']);
        $this->assertSame('abc123', $roundTrip['env']['git_commit']);
        unlink($path);
    }
}
