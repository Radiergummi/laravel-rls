<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[TestDox('Bench smoke')]
class BenchSmokeTest extends TestCase
{
    #[Test]
    #[TestDox('composer bench runs end-to-end at 1k and writes a valid baseline document')]
    public function bench_runs_and_writes_valid_json(): void
    {
        $json = tempnam(sys_get_temp_dir(), 'bench') . '.json';
        $root = dirname(__DIR__, 3);

        $cmd = sprintf(
            'php %s/bench/run.php --scale=1k --iterations=5 --warmup=2 --json=%s 2>&1',
            escapeshellarg($root),
            escapeshellarg($json),
        );
        exec($cmd, $output, $exit);

        $this->assertSame(0, $exit, "bench failed:\n" . implode("\n", $output));

        $doc = json_decode((string) file_get_contents($json), true);
        $this->assertArrayHasKey('env', $doc);
        $this->assertNotEmpty($doc['cells']);
        $this->assertNotEmpty($doc['explain']);
        $this->assertArrayHasKey('scan_type', $doc['explain'][0]);

        unlink($json);
    }
}
