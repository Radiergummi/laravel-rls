<?php

namespace Radiergummi\LaravelRls\Tests\Feature;

use Radiergummi\LaravelRls\Tests\TestCase;

class RlsAuditCommandTest extends TestCase
{
    public function test_reports_bypass_call_sites(): void
    {
        $path = __DIR__ . '/../fixtures/audit';

        $this->artisan('rls:audit', ['--path' => [$path]])
            ->expectsOutputToContain('BypassSample.php')
            ->expectsOutputToContain('2 bypass call site(s) found.')
            ->assertExitCode(0);
    }

    public function test_fails_when_bypass_count_exceeds_threshold(): void
    {
        $path = __DIR__ . '/../fixtures/audit'; // 2 call sites

        $this->artisan('rls:audit', ['--path' => [$path], '--threshold' => 1])
            ->assertExitCode(1);
    }

    public function test_passes_when_bypass_count_is_within_threshold(): void
    {
        $path = __DIR__ . '/../fixtures/audit'; // 2 call sites

        $this->artisan('rls:audit', ['--path' => [$path], '--threshold' => 2])
            ->assertExitCode(0);
    }
}
