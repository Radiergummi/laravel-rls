<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Radiergummi\LaravelRls\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    #[Test]
    public function registers_publishable_groups(): void
    {
        $groups = ServiceProvider::$publishGroups;

        $this->assertArrayHasKey('rls-config', $groups);
        $this->assertArrayHasKey('rls-migrations', $groups);
        $this->assertArrayHasKey('rls-provider', $groups);
    }

    #[Test]
    public function provider_stub_scaffolds_context_and_resolver(): void
    {
        $stub = file_get_contents(__DIR__ . '/../../stubs/rls-provider.stub');

        $this->assertStringContainsString('defineContext', $stub);
        $this->assertStringContainsString('resolveContextUsing', $stub);
    }

    #[Test]
    public function install_command_runs_and_prints_next_steps(): void
    {
        $this
            ->artisan('rls:install', ['--force' => true])
            ->expectsOutputToContain('laravel-rls installed.')
            ->expectsOutputToContain('RlsServiceProvider')
            ->assertExitCode(0);
    }
}
