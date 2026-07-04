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
}
