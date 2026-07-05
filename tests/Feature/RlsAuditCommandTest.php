<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Radiergummi\LaravelRls\Tests\TestCase;

use function assert;

class RlsAuditCommandTest extends TestCase
{
    #[Test]
    public function reports_bypass_call_sites(): void
    {
        $path = __DIR__ . '/../fixtures/audit';

        $result = $this->artisan('rls:audit', ['--path' => [$path]]);
        assert($result instanceof PendingCommand);
        $result
            ->expectsOutputToContain('BypassSample.php')
            ->expectsOutputToContain('2 bypass call site(s) found.')
            ->assertExitCode(0);
    }

    #[Test]
    public function fails_when_bypass_count_exceeds_threshold(): void
    {
        $path = __DIR__ . '/../fixtures/audit'; // 2 call sites

        $result = $this->artisan('rls:audit', [
            '--path' => [$path],
            '--threshold' => 1,
        ]);
        assert($result instanceof PendingCommand);
        $result->assertExitCode(1);
    }

    #[Test]
    public function passes_when_bypass_count_is_within_threshold(): void
    {
        $path = __DIR__ . '/../fixtures/audit'; // 2 call sites

        $result = $this->artisan('rls:audit', [
            '--path' => [$path],
            '--threshold' => 2,
        ]);
        assert($result instanceof PendingCommand);
        $result->assertExitCode(0);
    }
}
