<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Testing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Exception;
use PHPUnit\Framework\ExpectationFailedException;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Facades\Rls;
use RuntimeException;
use stdClass;

use function is_scalar;

trait InteractsWithRls
{
    /**
     * @template T = mixed
     *
     * @param array<string, mixed> $context
     * @param null|Closure(): T    $callback
     *
     * @return T
     *
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    protected function isolateTo(array $context, ?Closure $callback = null): mixed
    {
        return Rls::isolateTo($context, $callback);
    }

    /**
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     *
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    protected function withoutIsolation(string $reason, Closure $callback): mixed
    {
        return Rls::withoutIsolation($reason, $callback);
    }

    /**
     * @throws ExpectationFailedException
     * @throws Exception
     */
    protected function assertTableIsolated(string $table): void
    {
        $row = DB::selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class where relname = ?',
            [$table],
        );

        $this->assertNotNull($row, "Table {$table} not found");
        $this->assertIsObject($row);
        $this->assertInstanceOf(stdClass::class, $row);
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
        )->contains(fn(stdClass $policy) => $policy->permissive === 'RESTRICTIVE');

        $this->assertTrue(
            $hasRestrictive,
            "No restrictive isolation policy on {$table}",
        );
    }

    /**
     * @param class-string<Model> $modelClass
     * @param string              $isolatedBy the isolation key / model column to scope by
     *
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    protected function assertIsolates(
        string $modelClass,
        string $isolatedBy,
        mixed $acting,
        mixed $cannotSee,
    ): void {
        $actingId = $this->resolveKey($acting);
        $otherId = $this->resolveKey($cannotSee);

        Rls::isolateTo([$isolatedBy => $actingId], function () use (
            $modelClass,
            $otherId,
            $isolatedBy,
        ): void {
            $leaked = $modelClass::query()->where($isolatedBy, $otherId)->count();
            $this->assertSame(
                0,
                $leaked,
                // @phpstan-ignore encapsedStringPart.nonString (we know the isolation key is a string)
                "Rows scoped to {$otherId} are visible to the acting context",
            );
        });
    }

    /**
     * Assert that, under the given isolation context, every one of the given model keys is visible
     * (i.e. survives the RLS policy). Subset semantics: extra visible rows are fine.
     *
     * @param array<string, mixed> $context
     * @param class-string<Model>  $modelClass
     * @param array<int, mixed>    $ids
     *
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    protected function assertVisibleTo(array $context, string $modelClass, array $ids): void
    {
        Rls::isolateTo($context, function () use ($modelClass, $ids): void {
            $key = (new $modelClass())->getKeyName();
            $visible = $modelClass::query()->whereIn($key, $ids)->pluck($key)->all();

            $missing = array_values(array_diff($ids, $visible));

            $this->assertSame(
                [],
                $missing,
                'Expected rows are not visible under this context: ' . implode(', ', $missing),
            );
        });
    }

    /**
     * Assert that, under the given isolation context, none of the given model keys is visible (the
     * RLS policy hides every one of them).
     *
     * @param array<string, mixed> $context
     * @param class-string<Model>  $modelClass
     * @param array<int, mixed>    $ids
     *
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    protected function assertNotVisibleTo(array $context, string $modelClass, array $ids): void
    {
        Rls::isolateTo($context, function () use ($modelClass, $ids): void {
            $key = (new $modelClass())->getKeyName();
            $leaked = $modelClass::query()->whereIn($key, $ids)->pluck($key)->all();

            $this->assertSame(
                [],
                array_values($leaked),
                'Rows that should be hidden are visible under this context: ' . implode(', ', $leaked),
            );
        });
    }

    /**
     * @template T
     *
     * @param T $value
     *
     * @return scalar|T
     */
    protected function resolveKey(mixed $value): mixed
    {
        if (is_object($value) && method_exists($value, 'getKey')) {
            $key = $value->getKey();

            return is_scalar($key) ? $key : $value;
        }

        return $value;
    }

    /**
     * @param class-string<Model> $modelClass
     * @param string              $isolatedBy the isolation key / model column to scope by
     *
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    protected function assertRejectsForeignWrite(
        string $modelClass,
        string $isolatedBy,
        mixed $acting,
        mixed $foreign,
    ): void {
        $actingId = $this->resolveKey($acting);
        $foreignId = $this->resolveKey($foreign);

        Rls::isolateTo([$isolatedBy => $actingId], function () use (
            $modelClass,
            $foreignId,
            $isolatedBy,
        ): void {
            try {
                // Run in a savepoint so the expected policy violation rolls back cleanly without
                // aborting any surrounding transaction.
                DB::transaction(static function () use ($modelClass, $foreignId, $isolatedBy) {
                    $modelClass::query()->create([$isolatedBy => $foreignId]);
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
