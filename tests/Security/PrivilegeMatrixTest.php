<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Threat category 5 — the role/privilege matrix. State precisely who is confined:
 * superuser x BYPASSRLS x owner-no-FORCE x owner-FORCE x restricted, each against
 * read / write / bypass, including SET ROLE mid-session.
 *
 * When implementing: switch the base to SecurityTestCase (and add the extra roles
 * to tests/bin/setup-db.sh as needed).
 *
 * @see docs/MILESTONES.md — Milestone B, threat category 5
 */
#[TestDox('Security: role/privilege matrix (TODO)')]
class PrivilegeMatrixTest extends TestCase
{
    #[Test]
    #[TestDox('An owner without FORCE is not confined (documented), with FORCE is confined')]
    public function force_flag_decides_owner_confinement(): void
    {
        $this->markTestIncomplete('Milestone B §5: owner-no-FORCE vs owner-FORCE read/write confinement.');
    }

    #[Test]
    #[TestDox('A BYPASSRLS or superuser role skips policies (documented no-op)')]
    public function bypassrls_and_superuser_are_documented_no_ops(): void
    {
        $this->markTestIncomplete('Milestone B §5: superuser x BYPASSRLS skip every policy — state it precisely.');
    }

    #[Test]
    #[TestDox('SET ROLE mid-session does not smuggle a more-privileged role past isolation')]
    public function set_role_mid_session_is_contained(): void
    {
        $this->markTestIncomplete('Milestone B §5: SET ROLE mid-session against read/write/bypass.');
    }
}
