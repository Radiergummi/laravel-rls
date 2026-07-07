<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use Radiergummi\LaravelRls\Testing\InteractsWithRls;
use Radiergummi\LaravelRls\Tests\Fixtures\Models\Document;
use Radiergummi\LaravelRls\Tests\Fixtures\Models\Tenant;
use Radiergummi\LaravelRls\Tests\TestCase;

/**
 * Base for the adversarial security suite (Milestone B). These tests are written
 * from the attacker's side: each one tries to violate an isolation promise and
 * asserts it holds. Where a real leak exists it must be characterized and
 * documented, not hidden.
 *
 * Reuses the owner-mode `documents` fixture (isolatedBy tenant_id, FORCE on) and
 * wires the BYPASSRLS admin connection so bypass paths can be exercised. Two
 * tenants are seeded: A with COUNT_A documents, B with COUNT_B.
 */
abstract class SecurityTestCase extends TestCase
{
    use InteractsWithRls;

    protected const COUNT_A = 2;
    protected const COUNT_B = 3;

    protected Tenant $tenantA;
    protected Tenant $tenantB;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Bypass (system()/withoutIsolation()) routes to a BYPASSRLS connection;
        // the adversarial bypass tests need one configured to route to.
        $this->useBypassAdminConnection($app);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // tenants are not RLS-scoped: create the registry rows directly (in the
        // body, not a closure, so they read as initialized).
        $this->tenantA = Tenant::factory()->createOne();
        $this->tenantB = Tenant::factory()->createOne();

        // Seed each tenant's documents within that tenant's own context — WITH
        // CHECK permits same-tenant writes, so no bypass is needed to seed.
        $this->isolateTo(
            ['tenant_id' => $this->tenantA->id],
            fn() => Document::factory()->count(self::COUNT_A)->create(['tenant_id' => $this->tenantA->id]),
        );
        $this->isolateTo(
            ['tenant_id' => $this->tenantB->id],
            fn() => Document::factory()->count(self::COUNT_B)->create(['tenant_id' => $this->tenantB->id]),
        );
    }
}
