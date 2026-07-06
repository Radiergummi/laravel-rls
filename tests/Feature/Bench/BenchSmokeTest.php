<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature\Bench;

use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PHP_EOL;

#[TestDox('Bench smoke')]
class BenchSmokeTest extends TestCase
{
    /**
     * @throws JsonException
     */
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

        $this->assertSame(
            0,
            $exit,
            'Bench failed:' . PHP_EOL . implode(PHP_EOL, $output),
        );

        $document = json_decode(
            (string) file_get_contents($json),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($document);
        $this->assertArrayHasKey('env', $document);
        $this->assertNotEmpty($document['cells']);
        $this->assertNotEmpty($document['explain']);
        $this->assertArrayHasKey('scan_type', $document['explain'][0]);

        unlink($json);
    }
}
