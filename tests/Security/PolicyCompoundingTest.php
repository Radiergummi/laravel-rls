<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Threat category 4 — policy correctness under compounding conditions:
 * RESTRICTIVE + multiple permissive policies, compound isolation keys with a
 * partial context, mixed isolated/unisolated joins, and cross-tenant foreign
 * keys / unique constraints acting as existence oracles.
 *
 * When implementing: switch the base to SecurityTestCase.
 *
 * @see docs/MILESTONES.md — Milestone B, threat category 4
 */
#[TestDox('Security: policy compounding (TODO)')]
class PolicyCompoundingTest extends TestCase
{
    #[Test]
    #[TestDox('A partial compound context still confines every isolation key')]
    public function partial_compound_context_still_confines(): void
    {
        $this->markTestIncomplete('Milestone B §4: compound isolation keys with a partial context set.');
    }

    #[Test]
    #[TestDox('A cross-tenant unique/foreign-key violation does not leak another tenant\'s existence')]
    public function constraints_do_not_become_existence_oracles(): void
    {
        $this->markTestIncomplete('Milestone B §4: FKs and unique constraints as existence oracles across tenants.');
    }

    #[Test]
    #[TestDox('withDefault() cannot be overridden to write a foreign id')]
    public function with_default_cannot_be_overridden(): void
    {
        $this->markTestIncomplete('Milestone B §4: override the context default, insert NULL, or a foreign id despite WITH CHECK.');
    }
}
