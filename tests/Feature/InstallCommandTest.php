<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Tests\TestCase;

use function assert;

#[TestDox('rls:install Command')]
class InstallCommandTest extends TestCase
{
    #[Test]
    #[TestDox('rls:install registers publishable config, migration, and provider groups')]
    public function registers_publishable_groups(): void
    {
        $groups = ServiceProvider::$publishGroups;

        $this->assertArrayHasKey('rls-config', $groups);
        $this->assertArrayHasKey('rls-migrations', $groups);
        $this->assertArrayHasKey('rls-provider', $groups);
    }

    #[Test]
    #[TestDox('The provider stub scaffolds defineContext() and resolveContextUsing()')]
    public function provider_stub_scaffolds_context_and_resolver(): void
    {
        $stub = file_get_contents(__DIR__ . '/../../stubs/rls-provider.stub') ?: '';

        $this->assertStringContainsString('defineContext', $stub);
        $this->assertStringContainsString('resolveContextUsing', $stub);
    }

    #[Test]
    #[TestDox('rls:install runs and prints next steps')]
    public function install_command_runs_and_prints_next_steps(): void
    {
        $result = $this->artisan('rls:install', ['--force' => true]);
        assert($result instanceof PendingCommand);
        $result
            ->expectsOutputToContain('laravel-rls installed.')
            ->expectsOutputToContain('RlsServiceProvider')
            ->assertExitCode(0);
    }
}
