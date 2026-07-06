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
            'php %s/bench/run.php --scale=1k --iterations=5 --warmup=2 '
            . '--endpoint-iterations=3 --endpoint-warmup=1 --json=%s 2>&1',
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
        $this->assertIsArray($document['explain']);
        $this->assertIsArray($document['explain'][0]);
        $this->assertArrayHasKey('scan_type', $document['explain'][0]);

        $this->assertArrayHasKey('endpoints', $document);
        $this->assertNotEmpty($document['endpoints']);
        $this->assertArrayHasKey('label', $document['endpoints'][0]);
        $this->assertArrayHasKey('status', $document['endpoints'][0]);
        $this->assertArrayHasKey('k', $document['endpoints'][0]);
        $this->assertContains('direct·transaction·wrap', array_column($document['endpoints'], 'label'));
        $this->assertArrayHasKey('latency_sweep', $document);
        $this->assertArrayHasKey('toxiproxy', $document['env']);
        $this->assertArrayHasKey('pgbouncer', $document['env']);

        unlink($json);
    }

    #[Test]
    #[TestDox('the harness boots when APP_ENV is not testing (composer bench condition)')]
    public function bench_boots_without_app_env_in_the_environment(): void
    {
        // Guards the bench-boot fix: this suite spawns run.php as a subprocess that inherits
        // phpunit's APP_ENV=testing, so it structurally cannot catch a regression where the
        // container environment resolver (needed for class-level #[Bind] attribute bindings like
        // BypassHandler) is missing. Strip APP_ENV with `env -u` to reproduce the bare
        // `composer bench` shell and assert the harness still boots and resolves BypassHandler.
        $root = dirname(__DIR__, 3);

        $cmd = sprintf('env -u APP_ENV php %s 2>&1', escapeshellarg($root . '/tests/bin/bench-boot-probe.php'));
        exec($cmd, $output, $exit);

        $this->assertSame(
            0,
            $exit,
            'Boot failed without APP_ENV=testing:' . PHP_EOL . implode(PHP_EOL, $output),
        );
        $this->assertContains('OK', $output);
    }
}
