<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Testing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\ExpectationFailedException;
use Radiergummi\LaravelRls\Facades\Rls;

trait InteractsWithRls
{
    /**
     * @template T = mixed
     *
     * @param array<string, mixed> $context
     * @param null|Closure(): T    $callback
     *
     * @return T
     */
    protected function withRlsContext(array $context, ?Closure $callback = null): mixed
    {
        return Rls::isolateTo($context, $callback);
    }

    /**
     * @template T = mixed
     *
     * @param null|Closure(): T $callback
     *
     * @return T
     */
    protected function actingAsTenant(string|int $id, ?Closure $callback = null): mixed
    {
        return Rls::isolateTo(['tenant_id' => $id], $callback);
    }

    /**
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    protected function withoutRls(string $reason, Closure $callback): mixed
    {
        return Rls::withoutRls($reason, $callback);
    }

    /**
     * @throws ExpectationFailedException
     */
    protected function assertTableProtected(string $table): void
    {
        $row = DB::selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class where relname = ?',
            [$table],
        );

        $this->assertNotNull($row, "Table {$table} not found");
        $this->assertTrue(
            (bool) $row->relrowsecurity,
            "RLS not enabled on {$table}",
        );

        if (config('rls.role_model', 'owner') === 'owner') {
            $this->assertTrue(
                (bool) $row->relforcerowsecurity,
                "RLS not forced on {$table}",
            );
        }

        $hasRestrictive = collect(
            DB::select(
                'select permissive from pg_policies where tablename = ?',
                [$table],
            ),
        )->contains(fn($p) => $p->permissive === 'RESTRICTIVE');

        $this->assertTrue(
            $hasRestrictive,
            "No restrictive isolation policy on {$table}",
        );
    }

    /**
     * @param class-string<Model> $modelClass
     * @param string              $dimension  the context dimension / model column to scope by
     *                                        (defaults to tenant_id; pass e.g. 'org_id' for
     *                                        any other declared dimension)
     */
    protected function assertRlsIsolates(
        string $modelClass,
        mixed $from,
        mixed $cannotSee,
        string $dimension = 'tenant_id',
    ): void {
        $fromId = $this->tenantKey($from);
        $otherId = $this->tenantKey($cannotSee);

        Rls::isolateTo([$dimension => $fromId], function () use ($modelClass, $otherId, $dimension) {
            $leaked = $modelClass::query()->where($dimension, $otherId)->count();
            $this->assertSame(
                0,
                $leaked,
                "Rows scoped to {$otherId} are visible to the acting context",
            );
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
     * @param class-string<Model> $modelClass
     * @param string              $dimension  the context dimension / model column to scope by
     *                                        (defaults to tenant_id; pass e.g. 'org_id' for
     *                                        any other declared dimension)
     */
    protected function assertCannotWriteAcrossTenants(
        string $modelClass,
        mixed $actingAs,
        mixed $tenant,
        string $dimension = 'tenant_id',
    ): void {
        $actingId = $this->tenantKey($actingAs);
        $foreignId = $this->tenantKey($tenant);

        Rls::isolateTo([$dimension => $actingId], function () use ($modelClass, $foreignId, $dimension) {
            try {
                // Run in a savepoint so the expected policy violation rolls back cleanly without
                // aborting any surrounding transaction.
                DB::transaction(static function () use ($modelClass, $foreignId, $dimension) {
                    $modelClass::query()->create([$dimension => $foreignId]);
                });

                $this->fail('Expected WITH CHECK to reject the cross-context write');
            } catch (QueryException $exception) {
                $this->assertStringContainsStringIgnoringCase(
                    'row-level security',
                    $exception->getMessage(),
                );
            }
        });
    }

    /**
     * Leak canary — auto-invoked by Testbench via beforeApplicationDestroyed, so it runs even when
     * the test class defines its own tearDown().
     *
     * @throws ExpectationFailedException
     */
    protected function tearDownInteractsWithRls(): void
    {
        $leaked = Rls::hasContext();
        Rls::forget();

        $this->assertFalse(
            $leaked,
            'RLS context leaked past the test (stack not empty)',
        );
    }
}
