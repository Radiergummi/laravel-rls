<?php

namespace Radiergummi\Rls\Testing;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Radiergummi\Rls\Facades\Rls;

trait InteractsWithRls
{
    protected function withRlsContext(array $context, ?Closure $callback = null): mixed
    {
        return Rls::actingAs($context, $callback);
    }

    protected function actingAsTenant(string|int $id, ?Closure $callback = null): mixed
    {
        return Rls::actingAs(['tenant_id' => $id], $callback);
    }

    protected function withoutRls(string $reason, Closure $callback): mixed
    {
        return Rls::withoutRls($reason, $callback);
    }

    protected function assertTableProtected(string $table): void
    {
        $row = DB::selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class where relname = ?',
            [$table],
        );

        $this->assertNotNull($row, "Table {$table} not found");
        $this->assertTrue((bool) $row->relrowsecurity, "RLS not enabled on {$table}");

        if (config('rls.role_model', 'owner') === 'owner') {
            $this->assertTrue((bool) $row->relforcerowsecurity, "RLS not forced on {$table}");
        }

        $hasRestrictive = collect(DB::select(
            'select permissive from pg_policies where tablename = ?',
            [$table],
        ))->contains(fn ($p) => $p->permissive === 'RESTRICTIVE');

        $this->assertTrue($hasRestrictive, "No restrictive isolation policy on {$table}");
    }

    protected function assertRlsIsolates(string $modelClass, mixed $from, mixed $cannotSee): void
    {
        $fromId = $this->tenantKey($from);
        $otherId = $this->tenantKey($cannotSee);

        Rls::actingAs(['tenant_id' => $fromId], function () use ($modelClass, $otherId) {
            $leaked = $modelClass::query()->where('tenant_id', $otherId)->count();
            $this->assertSame(0, $leaked, "Rows from tenant {$otherId} are visible to the acting tenant");
        });
    }

    protected function assertCannotWriteAcrossTenants(string $modelClass, mixed $actingAs, mixed $tenant): void
    {
        $actingId = $this->tenantKey($actingAs);
        $foreignId = $this->tenantKey($tenant);

        Rls::actingAs(['tenant_id' => $actingId], function () use ($modelClass, $foreignId) {
            try {
                $modelClass::query()->create(['tenant_id' => $foreignId]);
                $this->fail('Expected WITH CHECK to reject the cross-tenant write');
            } catch (QueryException $e) {
                $this->assertStringContainsStringIgnoringCase('row-level security', $e->getMessage());
            }
        });
    }

    protected function tenantKey(mixed $value): mixed
    {
        if (is_object($value) && method_exists($value, 'getKey')) {
            return $value->getKey();
        }

        return $value;
    }

    /**
     * Leak canary — auto-invoked by Testbench via beforeApplicationDestroyed,
     * so it runs even when the test class defines its own tearDown().
     */
    protected function tearDownInteractsWithRls(): void
    {
        $leaked = Rls::hasContext();
        Rls::forget();

        $this->assertFalse($leaked, 'RLS context leaked past the test (stack not empty)');
    }
}
