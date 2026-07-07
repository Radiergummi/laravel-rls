<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Threat category 7 — migration / DDL hazards: a data migration under owner+FORCE
 * silently touching zero rows, and adding a policy to a table that already holds
 * data.
 *
 * When implementing: switch the base to SecurityTestCase.
 *
 * @see docs/MILESTONES.md — Milestone B, threat category 7
 */
#[TestDox('Security: migration / DDL hazards (TODO)')]
class MigrationDdlTest extends TestCase
{
    #[Test]
    #[TestDox('A data migration under owner+FORCE without context touches zero rows loudly, not silently')]
    public function data_migration_without_context_is_not_silent(): void
    {
        $this->markTestIncomplete('Milestone B §7: data migrations under owner+FORCE silently touching zero rows.');
    }

    #[Test]
    #[TestDox('Adding an isolation policy to a table that already has data behaves predictably')]
    public function adding_policy_to_populated_table_is_predictable(): void
    {
        $this->markTestIncomplete('Milestone B §7: adding a policy to a table that already has data.');
    }
}
