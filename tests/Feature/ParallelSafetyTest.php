<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;
use Throwable;

#[TestDox('Parallel safety of rls.* helpers')]
class ParallelSafetyTest extends TestCase
{
    #[Test]
    #[TestDox('rls.context() is declared PARALLEL SAFE so policy predicates do not disable parallel plans')]
    public function context_function_is_parallel_safe(): void
    {
        // proparallel: 's' = safe, 'r' = restricted, 'u' = unsafe. A policy predicate that calls an
        // unsafe function forces the *entire* query to run serially — a silent performance ceiling on
        // every isolated table.
        $marker = $this->selectSingleValueFromDatabase(
            'select proparallel as value from pg_proc '
            . "where proname = 'context' and pronamespace = 'rls'::regnamespace",
        );

        $this->assertSame('s', $marker);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    #[TestDox('a forced-parallel scan of an isolated table both parallelizes and stays correctly scoped')]
    public function isolation_holds_under_a_forced_parallel_plan(): void
    {
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();

        $this->seedRows($a, 2_000);
        $this->seedRows($b, 500);

        [$plan, $count] = Rls::isolateTo(['tenant_id' => $a], function (): array {
            return DB::transaction(function (): array {
                // Make a parallel seq scan the cheapest plan the planner can pick, so parallelism is
                // gated only on the RLS predicate's parallel-safety, not on cost heuristics.
                foreach ([
                    'max_parallel_workers_per_gather' => '2',
                    'parallel_setup_cost' => '0',
                    'parallel_tuple_cost' => '0',
                    'min_parallel_table_scan_size' => '0',
                    'enable_indexscan' => 'off',
                    'enable_bitmapscan' => 'off',
                ] as $setting => $value) {
                    DB::statement("set local {$setting} = {$value}");
                }

                $plan = collect(DB::select('explain select count(*) from widgets'))
                    ->map(static fn(object $row): array => (array) $row)
                    ->flatten()
                    ->implode("\n");

                return [$plan, DB::table('widgets')->count()];
            });
        });

        $this->assertStringContainsString('Parallel', $plan, "Plan was not parallelized:\n{$plan}");
        $this->assertSame(2_000, $count, 'Parallel workers did not see the isolation context');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->isolatedBy('tenant_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('widgets');
        parent::tearDown();
    }

    private function seedRows(string $tenantId, int $count): void
    {
        Rls::isolateTo(['tenant_id' => $tenantId], function () use ($tenantId, $count): void {
            foreach (array_chunk(range(1, $count), 500) as $chunk) {
                DB::table('widgets')->insert(array_map(
                    static fn(): array => ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId],
                    $chunk,
                ));
            }
        });
    }
}
