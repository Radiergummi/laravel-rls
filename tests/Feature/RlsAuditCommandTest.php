<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Tests\TestCase;

use function assert;

#[TestDox('rls:audit Command')]
class RlsAuditCommandTest extends TestCase
{
    #[Test]
    #[TestDox('rls:audit reports each bypass call site found')]
    public function reports_bypass_call_sites(): void
    {
        $path = __DIR__ . '/../Fixtures/Audit';

        $result = $this->artisan('rls:audit', ['--path' => [$path]]);
        assert($result instanceof PendingCommand);
        $result
            ->expectsOutputToContain('BypassSample.php')
            ->expectsOutputToContain('2 bypass call site(s) found.')
            ->assertExitCode(0);
    }

    #[Test]
    #[TestDox('rls:audit fails when the bypass count exceeds the threshold')]
    public function fails_when_bypass_count_exceeds_threshold(): void
    {
        $path = __DIR__ . '/../Fixtures/Audit'; // 2 call sites

        $result = $this->artisan('rls:audit', [
            '--path' => [$path],
            '--threshold' => 1,
        ]);
        assert($result instanceof PendingCommand);
        $result->assertExitCode(1);
    }

    #[Test]
    #[TestDox('rls:audit passes when the bypass count is within the threshold')]
    public function passes_when_bypass_count_is_within_threshold(): void
    {
        $path = __DIR__ . '/../Fixtures/Audit'; // 2 call sites

        $result = $this->artisan('rls:audit', [
            '--path' => [$path],
            '--threshold' => 2,
        ]);
        assert($result instanceof PendingCommand);
        $result->assertExitCode(0);
    }
}
