<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Context\RlsManager;
use Radiergummi\LaravelRls\Exceptions\AdminConnectionRequired;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\Fixtures\Models\Document;
use RuntimeException;

use function app;
use function assert;

/**
 * Threat category 2 — bypass abuse. The in-band bypass (the old `rls.bypass()`
 * GUC/clause) is gone; bypass is admin-connection-only in both role models.
 * These tests attack that model: a forged bypass GUC must be inert, the
 * in-flight isBypassing() flag must be exception-safe, and bypass must never
 * silently run unscoped work.
 *
 * @see docs/MILESTONES.md — Milestone B, threat category 2
 */
#[TestDox('Security: bypass abuse')]
class BypassAbuseTest extends SecurityTestCase
{
    #[Test]
    #[TestDox('A forged app.bypass GUC does not lift isolation')]
    public function forged_bypass_guc_is_inert(): void
    {
        // Set a bypass-looking GUC from the app role inside the same transaction
        // as the read. No policy consults it (the in-band bypass is gone), so
        // with no context the table stays fail-closed at zero rows.
        DB::transaction(function (): void {
            DB::statement("select set_config('app.bypass', 'true', true)");

            $this->assertSame(0, Document::query()->count());
        });
    }

    #[Test]
    #[TestDox('A forged app.bypass GUC cannot widen a scoped read to other tenants')]
    public function forged_bypass_guc_cannot_widen_scope(): void
    {
        $this->isolateTo(['tenant_id' => $this->tenantA->id], function (): void {
            DB::transaction(function (): void {
                DB::statement("select set_config('app.bypass', 'true', true)");

                $this->assertSame(self::COUNT_A, Document::query()->count());
            });
        });
    }

    #[Test]
    #[TestDox('isBypassing() resets to false after an exception thrown inside system()')]
    public function bypass_flag_resets_after_an_exception(): void
    {
        try {
            Rls::system('boom', function (): void {
                throw new RuntimeException('inside bypass');
            });
        } catch (RuntimeException) {
            // expected
        }

        $manager = app(RlsManager::class);
        assert($manager instanceof RlsManager);

        $this->assertFalse(
            $manager->isBypassing(),
            'The in-flight bypass flag stayed down after an exception — the fail-loud guard would stand '
            . 'down for the next query on this worker.',
        );
    }

    #[Test]
    #[TestDox('system() hard-fails before running the callback when no admin connection is configured')]
    public function system_never_runs_unscoped_without_an_admin_connection(): void
    {
        config(['rls.admin_connection' => null]);

        $ran = false;

        try {
            Rls::system('audit', function () use (&$ran): void {
                $ran = true;
            });

            $this->fail('Expected AdminConnectionRequired');
        } catch (AdminConnectionRequired) {
            // expected
        }

        $this->assertFalse(
            $ran,
            'The callback ran despite no admin connection — work could have executed unscoped.',
        );
    }

    #[Test]
    #[TestDox('Work outside a system() callback does not run on the admin connection')]
    public function default_connection_is_restored_after_bypass(): void
    {
        $before = DB::getDefaultConnection();

        Rls::system('audit', fn() => DB::table('documents')->count());

        $this->assertSame($before, DB::getDefaultConnection());
        $this->assertNotSame('pgsql_admin', DB::getDefaultConnection());
    }

    #[Test]
    #[TestDox('Nested system() restores the connection on each exit path')]
    public function nested_bypass_restores_each_level(): void
    {
        $admin = 'pgsql_admin';

        Rls::system('outer', function () use ($admin): void {
            $this->assertSame($admin, DB::getDefaultConnection());

            Rls::system('inner', function () use ($admin): void {
                $this->assertSame($admin, DB::getDefaultConnection());
            });

            // Inner restored to the outer's connection (still the admin one).
            $this->assertSame($admin, DB::getDefaultConnection());
        });

        $this->assertNotSame($admin, DB::getDefaultConnection());
    }
}
