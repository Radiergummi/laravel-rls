<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Threat category 8 — covert channels that leak another tenant's data without
 * reading its rows directly: sequence currval, pg_stat_*, EXPLAIN row estimates,
 * error-message oracles, and timing side channels.
 *
 * When implementing: switch the base to SecurityTestCase.
 *
 * @see docs/MILESTONES.md — Milestone B, threat category 8
 */
#[TestDox('Security: covert channels (TODO)')]
class CovertChannelTest extends TestCase
{
    #[Test]
    #[TestDox('Sequence currval / pg_stat_* do not leak another tenant\'s row counts')]
    public function statistics_surfaces_do_not_leak(): void
    {
        $this->markTestIncomplete('Milestone B §8: sequence currval, pg_stat_*, EXPLAIN row estimates.');
    }

    #[Test]
    #[TestDox('Error messages do not become existence oracles for foreign rows')]
    public function error_messages_are_not_oracles(): void
    {
        $this->markTestIncomplete('Milestone B §8: error-message oracles and timing side channels.');
    }
}
