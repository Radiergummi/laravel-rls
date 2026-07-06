# Performance Harness Implementation Plan (Milestone A v1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A dev-only benchmark harness that measures the RLS library's per-query cost (floor / control / treatment) with proper percentile statistics + `EXPLAIN` evidence, emitting a committed `bench/baseline.json` and a README-citable headline.

**Architecture:** Small, single-purpose PHP units under a non-shipped `bench/` tree (`autoload-dev` only). A one-time Testbench boot registers the real `RlsServiceProvider`; a `Runner` times `Scenario` operations across three variants via `hrtime(true)`; a pure `Stats` unit computes percentiles; an `ExplainProbe` captures DB-side plans; `JsonReporter`/`MarkdownReporter` emit results. Entry point `composer bench`.

**Tech Stack:** PHP 8.2+, Illuminate DB/Query Builder, Orchestra Testbench (dev), PHPUnit 11, PostgreSQL 18. Full design: [`../specs/2026-07-06-perf-harness-design.md`](../specs/2026-07-06-perf-harness-design.md).

## Global Constraints

- **Namespace:** harness code `Radiergummi\LaravelRls\Bench\` → `bench/`, registered ONLY under `autoload-dev` (never shipped in the published package). Tests `Radiergummi\LaravelRls\Tests\` → `tests/`. Copy verbatim.
- **Dev-only:** no changes to `src/`. No user-facing `rls:bench` command. Nothing added to the package's `autoload` (production) section.
- **DB identity:** the harness connects as owner `rls_app` (default `pgsql`) and seeds/bypasses via `rls_bypass` (`pgsql_admin`, `BYPASSRLS`). Both roles already exist (`tests/bin/setup-db.sh`). Postgres reachable at `127.0.0.1:5432`, db `rls_test`, password `secret`.
- **Scope (v1):** single worker; scales `1k` + `100k`; `transaction` strategy; `wrap` boundary; direct connection; single isolation key; warm cache. No concurrency, 10M, session/explicit/request, PgBouncer, compound policies, or pgbench.
- **Units:** samples collected in nanoseconds; all reported figures in microseconds.
- **Commit style:** conventional commits (`feat:`, `test:`, `chore:`, `docs:`). Commit after each task. End commit messages with:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`

---

## File Structure

```
composer.json                          # add autoload-dev PSR-4 for Bench\ + "bench" script  (Task 1, 8)
bench/
  Variant.php                          # enum Floor|Control|Treatment                          (Task 1)
  Stats.php                            # pure percentile/stddev summariser                     (Task 1)
  ExplainProbe.php                     # EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) + plan parse   (Task 2)
  Runner.php                           # warmup-discard + timed iterations -> ns samples        (Task 3)
  Env.php                              # env-metadata capture                                   (Task 4)
  Report/JsonReporter.php              # env-stamped baseline JSON writer                       (Task 4)
  Report/MarkdownReporter.php          # human overhead table + headline                        (Task 4)
  Boot.php                             # Testbench app factory (real provider + 2 connections)  (Task 5)
  TableSet.php                         # DTO: probe ids/ranges for a seeded scale               (Task 6)
  Schema.php                           # deterministic seed/drop of bench_{floor,control,treatment} (Task 6)
  Scenario/Scenario.php                # abstract base + contract                               (Task 7)
  Scenario/PointSelect.php  RangeScan.php  Aggregate.php  Insert.php                            (Task 7)
  run.php                              # CLI entry: wires everything, writes reports            (Task 8)
  baseline.json                        # generated + committed reference artifact               (Task 8)
tests/Unit/Bench/StatsTest.php                                                                 (Task 1)
tests/Unit/Bench/ExplainProbeTest.php                                                          (Task 2)
tests/Unit/Bench/RunnerTest.php                                                                (Task 3)
tests/Unit/Bench/JsonReporterTest.php  MarkdownReporterTest.php                                (Task 4)
tests/Feature/Bench/BootTest.php                                                               (Task 5)
tests/Feature/Bench/SchemaTest.php                                                             (Task 6)
tests/Feature/Bench/ScenarioTest.php                                                           (Task 7)
tests/Feature/Bench/BenchSmokeTest.php                                                         (Task 8)
```

Tasks 1–4 are pure/in-process (no app, no DB). Tasks 5–7 boot a real app + DB and MUST run in a separate PHPUnit process (attributes shown) to avoid facade/container clashes with the Testbench-based suite. Task 8 drives the CLI as a subprocess.

---

### Task 1: Bench namespace, Variant enum, and Stats

**Files:**
- Modify: `composer.json` (add `autoload-dev` PSR-4 entry for `Bench\`)
- Create: `bench/Variant.php`, `bench/Stats.php`
- Test: `tests/Unit/Bench/StatsTest.php`

**Interfaces:**
- Produces: `Radiergummi\LaravelRls\Bench\Variant` (enum: `Floor`, `Control`, `Treatment`, backed by lowercase string values). `Radiergummi\LaravelRls\Bench\Stats::summarize(array $samplesNs): array` returning `array{n:int, mean_us:float, stddev_us:float, min_us:float, max_us:float, p50_us:float, p90_us:float, p95_us:float, p98_us:float, p99_us:float}`; throws `InvalidArgumentException` on empty input. Percentiles use nearest-rank on ascending-sorted samples.

- [ ] **Step 1: Register the Bench namespace under autoload-dev**

In `composer.json`, extend the existing `autoload-dev.psr-4` block (which already maps `Radiergummi\\LaravelRls\\Tests\\` and the factories namespace) to add:

```json
"Radiergummi\\LaravelRls\\Bench\\": "bench/"
```

The full `autoload-dev` block becomes:

```json
"autoload-dev": {
    "psr-4": {
        "Radiergummi\\LaravelRls\\Tests\\": "tests/",
        "Radiergummi\\LaravelRls\\Tests\\Fixtures\\Database\\Factories\\": "tests/Fixtures/database/factories/",
        "Radiergummi\\LaravelRls\\Bench\\": "bench/"
    }
}
```

Run: `composer dump-autoload`
Expected: `Generated autoload files`.

- [ ] **Step 2: Write the failing Stats test**

`tests/Unit/Bench/StatsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit\Bench;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Stats;

#[TestDox('Bench Stats')]
class StatsTest extends TestCase
{
    #[Test]
    #[TestDox('summarize() computes percentiles, mean and stddev in microseconds')]
    public function summarizes_samples(): void
    {
        // 1_000..10_000 ns => 1..10 us
        $samples = [];
        for ($i = 1; $i <= 10; $i++) {
            $samples[] = $i * 1000;
        }

        $s = Stats::summarize($samples);

        $this->assertSame(10, $s['n']);
        $this->assertSame(1.0, $s['min_us']);
        $this->assertSame(10.0, $s['max_us']);
        $this->assertEqualsWithDelta(5.5, $s['mean_us'], 0.0001);
        // nearest-rank: p50 => ceil(0.5*10)=5 => index 4 => 5.0
        $this->assertSame(5.0, $s['p50_us']);
        $this->assertSame(9.0, $s['p90_us']);
        $this->assertSame(10.0, $s['p99_us']);
        $this->assertEqualsWithDelta(3.0277, $s['stddev_us'], 0.001);
    }

    #[Test]
    #[TestDox('summarize() handles a single sample')]
    public function single_sample(): void
    {
        $s = Stats::summarize([4200]);
        $this->assertSame(1, $s['n']);
        $this->assertSame(4.2, $s['p50_us']);
        $this->assertSame(4.2, $s['p99_us']);
        $this->assertSame(0.0, $s['stddev_us']);
    }

    #[Test]
    #[TestDox('summarize() rejects an empty sample set')]
    public function rejects_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Stats::summarize([]);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Bench/StatsTest.php`
Expected: FAIL — class `Radiergummi\LaravelRls\Bench\Stats` not found (and `Variant` not yet needed here).

- [ ] **Step 4: Write Variant and Stats**

`bench/Variant.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

enum Variant: string
{
    case Floor = 'floor';
    case Control = 'control';
    case Treatment = 'treatment';
}
```

`bench/Stats.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

use InvalidArgumentException;

use function array_map;
use function array_sum;
use function array_values;
use function ceil;
use function count;
use function max;
use function min;
use function sort;
use function sqrt;

/**
 * Pure statistics over a sample array. Input is nanosecond durations; output is microseconds.
 */
final class Stats
{
    /**
     * @param list<int|float> $samplesNs nanosecond durations
     *
     * @return array{n:int,mean_us:float,stddev_us:float,min_us:float,max_us:float,p50_us:float,p90_us:float,p95_us:float,p98_us:float,p99_us:float}
     */
    public static function summarize(array $samplesNs): array
    {
        if ($samplesNs === []) {
            throw new InvalidArgumentException('Cannot summarize an empty sample set.');
        }

        $us = array_map(static fn($ns): float => $ns / 1000.0, array_values($samplesNs));
        sort($us);

        $n = count($us);
        $mean = array_sum($us) / $n;

        $variance = 0.0;
        foreach ($us as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $stddev = $n > 1 ? sqrt($variance / ($n - 1)) : 0.0;

        return [
            'n' => $n,
            'mean_us' => $mean,
            'stddev_us' => $stddev,
            'min_us' => $us[0],
            'max_us' => $us[$n - 1],
            'p50_us' => self::percentile($us, 50),
            'p90_us' => self::percentile($us, 90),
            'p95_us' => self::percentile($us, 95),
            'p98_us' => self::percentile($us, 98),
            'p99_us' => self::percentile($us, 99),
        ];
    }

    /**
     * Nearest-rank percentile on an ascending-sorted list.
     *
     * @param list<float> $sorted
     */
    private static function percentile(array $sorted, int $p): float
    {
        $n = count($sorted);
        $rank = (int) ceil($p / 100 * $n);
        $index = max(1, min($rank, $n)) - 1;

        return $sorted[$index];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Bench/StatsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add composer.json bench/Variant.php bench/Stats.php tests/Unit/Bench/StatsTest.php
git commit -m "feat: bench namespace, Variant enum, and pure Stats summariser"
```

---

### Task 2: ExplainProbe (plan parser + live EXPLAIN)

**Files:**
- Create: `bench/ExplainProbe.php`
- Test: `tests/Unit/Bench/ExplainProbeTest.php`

**Interfaces:**
- Consumes: `Illuminate\Database\Connection` (for the live path only).
- Produces: `ExplainProbe::parse(array $explain): array` returning `array{scan_type:string, parallel:bool, exec_ms:float}` from a decoded `EXPLAIN ... FORMAT JSON` element (the `array{Plan:..., 'Execution Time'?:float}` shape). `scan_type` is the first node type containing `"Scan"` found top-down (`"Unknown"` if none). `parallel` is true if any node is `Parallel Aware` or a `Gather*` node. `ExplainProbe::probe(Connection, string $sql, array $bindings): array` runs the live EXPLAIN and returns the same shape.

- [ ] **Step 1: Write the failing parser test**

`tests/Unit/Bench/ExplainProbeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\ExplainProbe;

#[TestDox('Bench ExplainProbe parser')]
class ExplainProbeTest extends TestCase
{
    #[Test]
    #[TestDox('parse() reports a bitmap index scan as index-backed and non-parallel')]
    public function parses_bitmap_index_scan(): void
    {
        $explain = [
            'Plan' => [
                'Node Type' => 'Bitmap Heap Scan',
                'Relation Name' => 'bench_treatment',
                'Plans' => [
                    ['Node Type' => 'Bitmap Index Scan', 'Index Name' => 'bt_tenant_idx'],
                ],
            ],
            'Execution Time' => 0.734,
        ];

        $result = ExplainProbe::parse($explain);

        $this->assertSame('Bitmap Heap Scan', $result['scan_type']);
        $this->assertFalse($result['parallel']);
        $this->assertSame(0.734, $result['exec_ms']);
        $this->assertStringNotContainsStringIgnoringCase('seq', $result['scan_type']);
    }

    #[Test]
    #[TestDox('parse() reports a sequential scan')]
    public function parses_seq_scan(): void
    {
        $explain = [
            'Plan' => [
                'Node Type' => 'Aggregate',
                'Plans' => [
                    ['Node Type' => 'Seq Scan', 'Relation Name' => 'bench_floor'],
                ],
            ],
            'Execution Time' => 26.6,
        ];

        $result = ExplainProbe::parse($explain);

        $this->assertSame('Seq Scan', $result['scan_type']);
        $this->assertFalse($result['parallel']);
        $this->assertSame(26.6, $result['exec_ms']);
    }

    #[Test]
    #[TestDox('parse() detects a parallel plan via a Gather node')]
    public function detects_parallel(): void
    {
        $explain = [
            'Plan' => [
                'Node Type' => 'Gather',
                'Plans' => [
                    ['Node Type' => 'Parallel Seq Scan', 'Parallel Aware' => true],
                ],
            ],
            'Execution Time' => 5.0,
        ];

        $result = ExplainProbe::parse($explain);

        $this->assertTrue($result['parallel']);
        $this->assertSame('Parallel Seq Scan', $result['scan_type']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Bench/ExplainProbeTest.php`
Expected: FAIL — class `ExplainProbe` not found.

- [ ] **Step 3: Write ExplainProbe**

`bench/ExplainProbe.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

use Illuminate\Database\Connection;

use function is_array;
use function is_string;
use function json_decode;
use function reset;
use function str_contains;

use const JSON_THROW_ON_ERROR;

final class ExplainProbe
{
    /**
     * Run a live EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) and parse the plan.
     *
     * @param array<int, mixed> $bindings
     *
     * @return array{scan_type:string,parallel:bool,exec_ms:float}
     */
    public static function probe(Connection $connection, string $sql, array $bindings): array
    {
        $rows = $connection->select(
            'explain (analyze, buffers, format json) ' . $sql,
            $bindings,
        );

        $first = (array) $rows[0];
        $json = reset($first); // the single "QUERY PLAN" column, whatever its key case
        $decoded = is_string($json) ? json_decode($json, true, flags: JSON_THROW_ON_ERROR) : $json;

        /** @var array{Plan: array<string,mixed>, 'Execution Time'?: float} $element */
        $element = $decoded[0];

        return self::parse($element);
    }

    /**
     * @param array{Plan: array<string,mixed>, 'Execution Time'?: float} $explain
     *
     * @return array{scan_type:string,parallel:bool,exec_ms:float}
     */
    public static function parse(array $explain): array
    {
        $plan = $explain['Plan'];

        return [
            'scan_type' => self::firstScan($plan),
            'parallel' => self::hasParallel($plan),
            'exec_ms' => (float) ($explain['Execution Time'] ?? 0.0),
        ];
    }

    /**
     * First node type containing "Scan", walking top-down.
     *
     * @param array<string,mixed> $node
     */
    private static function firstScan(array $node): string
    {
        $type = is_string($node['Node Type'] ?? null) ? $node['Node Type'] : 'Unknown';

        if (str_contains($type, 'Scan')) {
            return $type;
        }

        foreach (self::children($node) as $child) {
            $found = self::firstScan($child);
            if ($found !== 'Unknown') {
                return $found;
            }
        }

        return 'Unknown';
    }

    /**
     * @param array<string,mixed> $node
     */
    private static function hasParallel(array $node): bool
    {
        $type = is_string($node['Node Type'] ?? null) ? $node['Node Type'] : '';

        if (($node['Parallel Aware'] ?? false) === true || str_contains($type, 'Gather')) {
            return true;
        }

        foreach (self::children($node) as $child) {
            if (self::hasParallel($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $node
     *
     * @return list<array<string,mixed>>
     */
    private static function children(array $node): array
    {
        $plans = $node['Plans'] ?? [];

        return is_array($plans)
            ? array_values(array_filter($plans, is_array(...)))
            : [];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Bench/ExplainProbeTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add bench/ExplainProbe.php tests/Unit/Bench/ExplainProbeTest.php
git commit -m "feat: ExplainProbe plan parser and live EXPLAIN capture"
```

---

### Task 3: Runner

**Files:**
- Create: `bench/Runner.php`
- Test: `tests/Unit/Bench/RunnerTest.php`

**Interfaces:**
- Consumes: `Variant` (Task 1). A callable operation `Closure(Variant): void`.
- Produces: `Runner::measure(Closure $operation, Variant $variant, int $warmup, int $iterations): array` returning a `list<int>` of nanosecond durations of length `$iterations` (warmup runs discarded). Uses `hrtime(true)`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Bench/RunnerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Runner;
use Radiergummi\LaravelRls\Bench\Variant;

#[TestDox('Bench Runner')]
class RunnerTest extends TestCase
{
    #[Test]
    #[TestDox('measure() returns one positive ns sample per measured iteration, excluding warmup')]
    public function measures_iterations(): void
    {
        $calls = 0;
        $operation = function (Variant $variant) use (&$calls): void {
            $calls++;
        };

        $samples = (new Runner())->measure($operation, Variant::Treatment, warmup: 3, iterations: 5);

        $this->assertCount(5, $samples);
        $this->assertSame(8, $calls, 'warmup (3) + measured (5) invocations');

        foreach ($samples as $ns) {
            $this->assertIsInt($ns);
            $this->assertGreaterThanOrEqual(0, $ns);
        }
    }

    #[Test]
    #[TestDox('measure() passes the variant through to the operation')]
    public function passes_variant(): void
    {
        $seen = null;
        (new Runner())->measure(
            function (Variant $variant) use (&$seen): void {
                $seen = $variant;
            },
            Variant::Control,
            warmup: 0,
            iterations: 1,
        );

        $this->assertSame(Variant::Control, $seen);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Bench/RunnerTest.php`
Expected: FAIL — class `Runner` not found.

- [ ] **Step 3: Write Runner**

`bench/Runner.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

use Closure;

use function hrtime;

final class Runner
{
    /**
     * Execute the operation `warmup` times (discarded) then `iterations` times, returning one
     * nanosecond duration per measured iteration.
     *
     * @param Closure(Variant): void $operation
     *
     * @return list<int>
     */
    public function measure(Closure $operation, Variant $variant, int $warmup, int $iterations): array
    {
        for ($i = 0; $i < $warmup; $i++) {
            $operation($variant);
        }

        $samples = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $operation($variant);
            $samples[] = hrtime(true) - $start;
        }

        return $samples;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Bench/RunnerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add bench/Runner.php tests/Unit/Bench/RunnerTest.php
git commit -m "feat: Runner with warmup-discard timed iterations"
```

---

### Task 4: Reporters and Env

**Files:**
- Create: `bench/Env.php`, `bench/Report/JsonReporter.php`, `bench/Report/MarkdownReporter.php`
- Test: `tests/Unit/Bench/JsonReporterTest.php`, `tests/Unit/Bench/MarkdownReporterTest.php`

**Interfaces:**
- Consumes: cell arrays shaped like `Stats::summarize()` output plus `scenario`/`variant`/`scale` keys; explain arrays shaped like `ExplainProbe::parse()` output plus `scenario`/`scale`; an `env` array; a `params` array; an `amortization` list.
- Produces:
  - `Env::describe(string $pgVersion, string $gitCommit, string $generatedAt, bool $pgbouncer, bool $emulatePrepares): array` → the `env` block (adds `php_version`, `uname`).
  - `JsonReporter::render(array $env, array $params, array $cells, array $amortization, array $explain): array` (assembled document) and `JsonReporter::write(string $path, array $document): void`.
  - `MarkdownReporter::render(array $document): string` returning a table plus a `Headline:` line. `MarkdownReporter::headline(array $document): string` returning just the one-liner.

- [ ] **Step 1: Write the failing JsonReporter test**

`tests/Unit/Bench/JsonReporterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Env;
use Radiergummi\LaravelRls\Bench\Report\JsonReporter;

use function json_decode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[TestDox('Bench JsonReporter')]
class JsonReporterTest extends TestCase
{
    #[Test]
    #[TestDox('render() assembles an env-stamped baseline document')]
    public function renders_document(): void
    {
        $env = Env::describe('PostgreSQL 18.0', 'abc123', '2026-07-06T00:00:00Z', false, false);
        $doc = (new JsonReporter())->render(
            $env,
            ['iterations' => 2000, 'warmup' => 200, 'scales' => ['1k', '100k']],
            [['scenario' => 'point_select', 'variant' => 'treatment', 'scale' => '1k', 'n' => 2000, 'p50_us' => 1.2]],
            [['scale' => '1k', 'per_txn_1_query_us' => 40.0, 'per_txn_10_query_us' => 4.5, 'derived_fixed_setconfig_us' => 35.5]],
            [['scenario' => 'range_scan', 'scale' => '100k', 'scan_type' => 'Bitmap Heap Scan', 'parallel' => false, 'exec_ms' => 0.7]],
        );

        $this->assertSame('abc123', $doc['env']['git_commit']);
        $this->assertSame(PHP_VERSION, $doc['env']['php_version']);
        $this->assertSame(2000, $doc['params']['iterations']);
        $this->assertSame('point_select', $doc['cells'][0]['scenario']);
        $this->assertSame('Bitmap Heap Scan', $doc['explain'][0]['scan_type']);
        $this->assertSame(35.5, $doc['amortization'][0]['derived_fixed_setconfig_us']);
    }

    #[Test]
    #[TestDox('write() emits valid JSON to disk')]
    public function writes_json(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bench');
        $reporter = new JsonReporter();
        $doc = $reporter->render(
            Env::describe('PostgreSQL 18.0', 'abc123', '2026-07-06T00:00:00Z', false, false),
            ['iterations' => 5, 'warmup' => 2, 'scales' => ['1k']],
            [],
            [],
            [],
        );

        $reporter->write($path, $doc);
        $roundTrip = json_decode((string) file_get_contents($path), true);

        $this->assertSame('abc123', $roundTrip['env']['git_commit']);
        unlink($path);
    }
}
```

- [ ] **Step 2: Write the failing MarkdownReporter test**

`tests/Unit/Bench/MarkdownReporterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Report\MarkdownReporter;

use function str_contains;

#[TestDox('Bench MarkdownReporter')]
class MarkdownReporterTest extends TestCase
{
    #[Test]
    #[TestDox('render() produces a table and a headline citing per-query and per-transaction cost')]
    public function renders_table_and_headline(): void
    {
        $document = [
            'env' => ['pg_version' => 'PostgreSQL 18.0'],
            'params' => ['iterations' => 2000],
            'cells' => [
                ['scenario' => 'point_select', 'variant' => 'control', 'scale' => '100k', 'p50_us' => 1.0, 'p99_us' => 2.0],
                ['scenario' => 'point_select', 'variant' => 'treatment', 'scale' => '100k', 'p50_us' => 1.6, 'p99_us' => 3.2],
            ],
            'amortization' => [
                ['scale' => '100k', 'derived_fixed_setconfig_us' => 35.5],
            ],
            'explain' => [],
        ];

        $md = (new MarkdownReporter())->render($document);

        $this->assertStringContainsString('point_select', $md);
        $this->assertStringContainsString('Headline:', $md);
        $this->assertTrue(str_contains($md, 'p50') && str_contains($md, 'p99'));
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Bench/JsonReporterTest.php tests/Unit/Bench/MarkdownReporterTest.php`
Expected: FAIL — `Env`, `JsonReporter`, `MarkdownReporter` not found.

- [ ] **Step 4: Write Env**

`bench/Env.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

use function php_uname;

final class Env
{
    /**
     * @return array{pg_version:string,php_version:string,uname:string,emulate_prepares:bool,pgbouncer:bool,git_commit:string,generated_at:string}
     */
    public static function describe(
        string $pgVersion,
        string $gitCommit,
        string $generatedAt,
        bool $pgbouncer,
        bool $emulatePrepares,
    ): array {
        return [
            'pg_version' => $pgVersion,
            'php_version' => PHP_VERSION,
            'uname' => php_uname(),
            'emulate_prepares' => $emulatePrepares,
            'pgbouncer' => $pgbouncer,
            'git_commit' => $gitCommit,
            'generated_at' => $generatedAt,
        ];
    }
}
```

- [ ] **Step 5: Write JsonReporter**

`bench/Report/JsonReporter.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Report;

use function file_put_contents;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

final class JsonReporter
{
    /**
     * @param array<string,mixed>       $env
     * @param array<string,mixed>       $params
     * @param list<array<string,mixed>> $cells
     * @param list<array<string,mixed>> $amortization
     * @param list<array<string,mixed>> $explain
     *
     * @return array<string,mixed>
     */
    public function render(array $env, array $params, array $cells, array $amortization, array $explain): array
    {
        return [
            'env' => $env,
            'params' => $params,
            'cells' => $cells,
            'amortization' => $amortization,
            'explain' => $explain,
        ];
    }

    /**
     * @param array<string,mixed> $document
     */
    public function write(string $path, array $document): void
    {
        file_put_contents(
            $path,
            json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }
}
```

- [ ] **Step 6: Write MarkdownReporter**

`bench/Report/MarkdownReporter.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Report;

use function array_filter;
use function number_format;
use function reset;
use function sprintf;

final class MarkdownReporter
{
    /**
     * @param array<string,mixed> $document
     */
    public function render(array $document): string
    {
        $lines = [];
        $lines[] = '# RLS performance baseline';
        $lines[] = '';
        $lines[] = '| scenario | scale | variant | p50 (us) | p99 (us) |';
        $lines[] = '|---|---|---|---|---|';

        /** @var list<array<string,mixed>> $cells */
        $cells = $document['cells'] ?? [];
        foreach ($cells as $cell) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                $cell['scenario'],
                $cell['scale'],
                $cell['variant'],
                number_format((float) $cell['p50_us'], 2),
                number_format((float) $cell['p99_us'], 2),
            );
        }

        $lines[] = '';
        $lines[] = 'Headline: ' . $this->headline($document);

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string,mixed> $document
     */
    public function headline(array $document): string
    {
        /** @var list<array<string,mixed>> $cells */
        $cells = $document['cells'] ?? [];

        $treatment = array_filter(
            $cells,
            static fn(array $c): bool => ($c['variant'] ?? null) === 'treatment',
        );
        $control = array_filter(
            $cells,
            static fn(array $c): bool => ($c['variant'] ?? null) === 'control',
        );

        $treatmentCell = reset($treatment);
        $controlCell = reset($control);

        $p50Delta = $treatmentCell && $controlCell
            ? (float) $treatmentCell['p50_us'] - (float) $controlCell['p50_us']
            : 0.0;
        $p99Delta = $treatmentCell && $controlCell
            ? (float) $treatmentCell['p99_us'] - (float) $controlCell['p99_us']
            : 0.0;

        /** @var list<array<string,mixed>> $amortization */
        $amortization = $document['amortization'] ?? [];
        $amortCell = reset($amortization);
        $fixed = $amortCell ? (float) $amortCell['derived_fixed_setconfig_us'] : 0.0;

        return sprintf(
            'adds ~%s us p50 / ~%s us p99 per query and ~one round-trip (~%s us) per transaction',
            number_format($p50Delta, 2),
            number_format($p99Delta, 2),
            number_format($fixed, 2),
        );
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Bench/JsonReporterTest.php tests/Unit/Bench/MarkdownReporterTest.php`
Expected: PASS (3 tests total).

- [ ] **Step 8: Commit**

```bash
git add bench/Env.php bench/Report tests/Unit/Bench/JsonReporterTest.php tests/Unit/Bench/MarkdownReporterTest.php
git commit -m "feat: Env capture, JSON baseline reporter, and markdown reporter"
```

---

### Task 5: Boot (Testbench app factory)

**Files:**
- Create: `bench/Boot.php`
- Test: `tests/Feature/Bench/BootTest.php`

**Interfaces:**
- Consumes: `Radiergummi\LaravelRls\RlsServiceProvider`, Orchestra Testbench.
- Produces: `Boot::app(): \Illuminate\Contracts\Foundation\Application` — a booted app with `RlsServiceProvider` registered, `database.default = pgsql` (as `rls_app`), a `pgsql_admin` connection (as `rls_bypass`), `rls.role_model = owner`, `rls.admin_connection = pgsql_admin`. The default connection resolves to an `RlsPostgresConnection`.

> **Implementation note (read before Step 3):** the exact Testbench functional API (`Orchestra\Testbench\Foundation\Application::create`) can vary between testbench-core `^9` and `^10`. If the signature below does not match, consult `vendor/orchestra/testbench-core/src/Foundation/Application.php` (method `create`) and the `Orchestra\Testbench\Concerns\CreatesApplication` trait. The GOAL is fixed: a booted app with our provider registered and the two connections configured. The `BootTest` below is the arbiter — iterate until it passes.

- [ ] **Step 1: Write the failing boot test**

`tests/Feature/Bench/BootTest.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature\Bench;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Boot;
use Radiergummi\LaravelRls\Database\RlsPostgresConnection;

#[TestDox('Bench Boot')]
class BootTest extends TestCase
{
    #[Test]
    #[TestDox('Boot::app() returns an app whose default connection is an RlsPostgresConnection')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function boots_a_real_rls_app(): void
    {
        $app = Boot::app();

        $connection = $app->make('db')->connection();
        $this->assertInstanceOf(RlsPostgresConnection::class, $connection);

        // A trivial query proves the connection is live and configured.
        $this->assertSame('rls_app', $connection->selectOne('select current_user as u')->u);

        // The admin connection is configured and bypasses RLS.
        $this->assertSame('rls_bypass', $app->make('db')->connection('pgsql_admin')->selectOne('select current_user as u')->u);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Bench/BootTest.php`
Expected: FAIL — class `Boot` not found.

- [ ] **Step 3: Write Boot**

`bench/Boot.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Radiergummi\LaravelRls\RlsServiceProvider;

use function tap;

final class Boot
{
    public static function app(): Application
    {
        return Testbench::create(
            basePath: null,
            resolvingCallback: static function (Application $app): void {
                tap($app->make('config'), static function (Repository $config): void {
                    $connection = static fn(string $user): array => [
                        'driver' => 'pgsql',
                        'host' => '127.0.0.1',
                        'port' => 5432,
                        'database' => 'rls_test',
                        'username' => $user,
                        'password' => 'secret',
                        'charset' => 'utf8',
                        'search_path' => 'public',
                        'sslmode' => 'prefer',
                    ];

                    $config->set('database.default', 'pgsql');
                    $config->set('database.connections.pgsql', $connection('rls_app'));
                    $config->set('database.connections.pgsql_admin', $connection('rls_bypass'));
                    $config->set('rls.role_model', 'owner');
                    $config->set('rls.admin_connection', 'pgsql_admin');
                });
            },
            options: ['extra' => ['providers' => [RlsServiceProvider::class]]],
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Bench/BootTest.php`
Expected: PASS (1 test). If it fails on the Testbench API, follow the implementation note above and iterate against this test.

- [ ] **Step 5: Commit**

```bash
git add bench/Boot.php tests/Feature/Bench/BootTest.php
git commit -m "feat: Boot factory that boots a real RLS app via Testbench"
```

---

### Task 6: Schema + TableSet (deterministic seed)

**Files:**
- Create: `bench/TableSet.php`, `bench/Schema.php`
- Test: `tests/Feature/Bench/SchemaTest.php`

**Interfaces:**
- Consumes: `Boot::app()` (Task 5), the booted app's DB + Schema builder, the `isolatedBy` macro (registered by the provider), the `rls_bypass` (`pgsql_admin`) connection.
- Produces:
  - `TableSet` — readonly DTO: `string $scale`, `string $probeTenantId`, `string $probeRowId`, `int $probeRangeLo`, `int $probeRangeHi`, and constants `FLOOR='bench_floor'`, `CONTROL='bench_control'`, `TREATMENT='bench_treatment'`.
  - `Schema::__construct(Application $app)`; `Schema::rowCount(string $scale): int` (1000 for `'1k'`, 100000 for `'100k'`); `Schema::seed(string $scale): TableSet` (drops + recreates the three tables, indexes the scoping column, seeds identical deterministic data via `pgsql_admin`, returns the probe DTO); `Schema::drop(): void`.

Seeding rules: rows spread across a fixed 100 tenants (`sprintf('00000000-0000-0000-0000-%012d', $t)` for `$t` in `0..99`). Row `i` (`0-based`) has `tenant_id = tenants[i % 100]`, `n = i`, `id = sprintf('00000000-0000-4000-8000-%012d', i)`. Probe tenant = tenant `42`; probe row = the row whose `n` is the first index with `i % 100 === 42` (i.e. `n = 42`); probe range = `[0, rowCount/10]`.

- [ ] **Step 1: Write the failing schema test**

`tests/Feature/Bench/SchemaTest.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature\Bench;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Boot;
use Radiergummi\LaravelRls\Bench\Schema;
use Radiergummi\LaravelRls\Bench\TableSet;

#[TestDox('Bench Schema')]
class SchemaTest extends TestCase
{
    #[Test]
    #[TestDox('seed() creates and fills the three tables with identical deterministic data')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function seeds_identical_data(): void
    {
        $app = Boot::app();
        $schema = new Schema($app);

        try {
            $tables = $schema->seed('1k');
            $admin = $app->make('db')->connection('pgsql_admin');

            $this->assertInstanceOf(TableSet::class, $tables);
            $this->assertSame(1000, (int) $admin->table(TableSet::FLOOR)->count());
            $this->assertSame(1000, (int) $admin->table(TableSet::CONTROL)->count());
            $this->assertSame(1000, (int) $admin->table(TableSet::TREATMENT)->count());

            // The probe row exists in the probe tenant.
            $row = $admin->table(TableSet::TREATMENT)->where('id', $tables->probeRowId)->first();
            $this->assertNotNull($row);
            $this->assertSame($tables->probeTenantId, $row->tenant_id);

            // The scoping column is indexed on the treatment table.
            $indexed = $admin->selectOne(
                "select 1 as ok from pg_indexes where tablename = ? and indexdef like '%tenant_id%'",
                [TableSet::TREATMENT],
            );
            $this->assertNotNull($indexed, 'treatment.tenant_id must be indexed');
        } finally {
            $schema->drop();
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Bench/SchemaTest.php`
Expected: FAIL — `Schema`/`TableSet` not found.

- [ ] **Step 3: Write TableSet**

`bench/TableSet.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

final readonly class TableSet
{
    public const string FLOOR = 'bench_floor';
    public const string CONTROL = 'bench_control';
    public const string TREATMENT = 'bench_treatment';

    public function __construct(
        public string $scale,
        public string $probeTenantId,
        public string $probeRowId,
        public int $probeRangeLo,
        public int $probeRangeHi,
    ) {}
}
```

- [ ] **Step 4: Write Schema**

`bench/Schema.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;

use function array_chunk;
use function sprintf;

final class Schema
{
    private const int TENANTS = 100;
    private const int PROBE_TENANT = 42;

    public function __construct(private readonly Application $app) {}

    public function rowCount(string $scale): int
    {
        return match ($scale) {
            '1k' => 1000,
            '100k' => 100000,
            default => throw new InvalidArgumentException("Unknown scale: {$scale}"),
        };
    }

    public function seed(string $scale): TableSet
    {
        $rows = $this->rowCount($scale);
        $this->drop();

        $builder = $this->app->make('db')->connection()->getSchemaBuilder();

        // floor + control are plain tables; treatment is isolated via the macro. All three carry
        // the same columns and a tenant_id index so index behaviour is measured, not absent.
        foreach ([TableSet::FLOOR, TableSet::CONTROL] as $table) {
            $builder->create($table, static function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id')->index();
                $t->integer('n')->index();
                $t->string('payload');
            });
        }

        $builder->create(TableSet::TREATMENT, static function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->integer('n')->index();
            $t->string('payload');
            $t->isolatedBy('tenant_id');
        });

        $this->fill($rows);

        return new TableSet(
            scale: $scale,
            probeTenantId: $this->tenantId(self::PROBE_TENANT),
            probeRowId: $this->rowId(self::PROBE_TENANT), // row n = 42 belongs to tenant 42
            probeRangeLo: 0,
            probeRangeHi: (int) ($rows / 10),
        );
    }

    public function drop(): void
    {
        $builder = $this->app->make('db')->connection('pgsql_admin')->getSchemaBuilder();
        $builder->dropIfExists(TableSet::TREATMENT);
        $builder->dropIfExists(TableSet::CONTROL);
        $builder->dropIfExists(TableSet::FLOOR);
    }

    private function fill(int $rows): void
    {
        // Seed via the BYPASSRLS admin connection so the FORCE-bound treatment table's WITH CHECK
        // does not reject the cross-tenant bulk load. Identical rows into all three tables.
        $admin = $this->app->make('db')->connection('pgsql_admin');

        $records = [];
        for ($i = 0; $i < $rows; $i++) {
            $records[] = [
                'id' => $this->rowId($i),
                'tenant_id' => $this->tenantId($i % self::TENANTS),
                'n' => $i,
                'payload' => 'x',
            ];
        }

        foreach ([TableSet::FLOOR, TableSet::CONTROL, TableSet::TREATMENT] as $table) {
            foreach (array_chunk($records, 1000) as $chunk) {
                $admin->table($table)->insert($chunk);
            }
        }
    }

    private function tenantId(int $t): string
    {
        return sprintf('00000000-0000-0000-0000-%012d', $t);
    }

    private function rowId(int $i): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $i);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Bench/SchemaTest.php`
Expected: PASS (1 test).

- [ ] **Step 6: Commit**

```bash
git add bench/TableSet.php bench/Schema.php tests/Feature/Bench/SchemaTest.php
git commit -m "feat: deterministic bench schema seeding (floor/control/treatment)"
```

---

### Task 7: Scenario contract and the four query shapes

**Files:**
- Create: `bench/Scenario/Scenario.php`, `bench/Scenario/PointSelect.php`, `bench/Scenario/RangeScan.php`, `bench/Scenario/Aggregate.php`, `bench/Scenario/Insert.php`
- Test: `tests/Feature/Bench/ScenarioTest.php`

**Interfaces:**
- Consumes: `Boot::app()`, `Schema`/`TableSet`, `Variant`, the `rls` manager (`isolateTo`).
- Produces: abstract `Scenario` with `__construct(Application $app, TableSet $tables)`, `abstract name(): string`, `abstract run(Variant $variant): void` (the single timed op), `explainTarget(): ?array` (default `null`; read scenarios return `array{sql:string, bindings:list<mixed>, tenant:string}` for the treatment read). Four concrete scenarios named `point_select`, `range_scan`, `aggregate`, `insert`. Treatment `run()`/`explainTarget()` reads must be scoped by wrapping in `Rls::isolateTo`.

- [ ] **Step 1: Write the failing scenario test**

`tests/Feature/Bench/ScenarioTest.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature\Bench;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Boot;
use Radiergummi\LaravelRls\Bench\Scenario\Aggregate;
use Radiergummi\LaravelRls\Bench\Scenario\Insert;
use Radiergummi\LaravelRls\Bench\Scenario\PointSelect;
use Radiergummi\LaravelRls\Bench\Scenario\RangeScan;
use Radiergummi\LaravelRls\Bench\Schema;
use Radiergummi\LaravelRls\Bench\TableSet;
use Radiergummi\LaravelRls\Bench\Variant;

#[TestDox('Bench Scenario')]
class ScenarioTest extends TestCase
{
    #[Test]
    #[TestDox('Every scenario runs each variant without error; treatment and control agree on counts')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function scenarios_run_all_variants(): void
    {
        $app = Boot::app();
        $schema = new Schema($app);

        try {
            $tables = $schema->seed('1k');
            $admin = $app->make('db')->connection('pgsql_admin');

            // control-scoped count of the probe tenant, computed via the bypass connection.
            $expected = (int) $admin->table(TableSet::CONTROL)
                ->where('tenant_id', $tables->probeTenantId)->count();
            $this->assertGreaterThan(0, $expected);

            foreach ([PointSelect::class, RangeScan::class, Aggregate::class, Insert::class] as $class) {
                $scenario = new $class($app, $tables);
                foreach (Variant::cases() as $variant) {
                    // Must not throw for any variant.
                    $scenario->run($variant);
                }
                $this->assertNotSame('', $scenario->name());
            }

            // The Aggregate treatment count equals the control count (same rows via RLS).
            $aggregate = new Aggregate($app, $tables);
            $treatmentCount = $app->make('rls')->isolateTo(
                ['tenant_id' => $tables->probeTenantId],
                static fn() => (int) $app->make('db')->connection()->table(TableSet::TREATMENT)->count(),
            );
            $this->assertSame($expected, $treatmentCount);
        } finally {
            $schema->drop();
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Bench/ScenarioTest.php`
Expected: FAIL — scenario classes not found.

- [ ] **Step 3: Write the Scenario base**

`bench/Scenario/Scenario.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Scenario;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Radiergummi\LaravelRls\Bench\TableSet;
use Radiergummi\LaravelRls\Bench\Variant;
use Radiergummi\LaravelRls\Context\RlsManager;

abstract class Scenario
{
    public function __construct(
        protected readonly Application $app,
        protected readonly TableSet $tables,
    ) {}

    abstract public function name(): string;

    abstract public function run(Variant $variant): void;

    /**
     * The treatment read for the DB-side EXPLAIN probe, or null for write scenarios.
     *
     * @return null|array{sql:string,bindings:list<mixed>,tenant:string}
     */
    public function explainTarget(): ?array
    {
        return null;
    }

    protected function db(): Connection
    {
        return $this->app->make('db')->connection();
    }

    protected function rls(): RlsManager
    {
        return $this->app->make('rls');
    }
}
```

- [ ] **Step 4: Write PointSelect**

`bench/Scenario/PointSelect.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Scenario;

use Radiergummi\LaravelRls\Bench\TableSet;
use Radiergummi\LaravelRls\Bench\Variant;

final class PointSelect extends Scenario
{
    public function name(): string
    {
        return 'point_select';
    }

    public function run(Variant $variant): void
    {
        match ($variant) {
            Variant::Floor => $this->db()->select(
                'select * from ' . TableSet::FLOOR . ' where id = ?',
                [$this->tables->probeRowId],
            ),
            Variant::Control => $this->db()->select(
                'select * from ' . TableSet::CONTROL . ' where id = ? and tenant_id = ?',
                [$this->tables->probeRowId, $this->tables->probeTenantId],
            ),
            Variant::Treatment => $this->rls()->isolateTo(
                ['tenant_id' => $this->tables->probeTenantId],
                fn() => $this->db()->select(
                    'select * from ' . TableSet::TREATMENT . ' where id = ?',
                    [$this->tables->probeRowId],
                ),
            ),
        };
    }

    public function explainTarget(): ?array
    {
        return [
            'sql' => 'select * from ' . TableSet::TREATMENT . ' where id = ?',
            'bindings' => [$this->tables->probeRowId],
            'tenant' => $this->tables->probeTenantId,
        ];
    }
}
```

- [ ] **Step 5: Write RangeScan**

`bench/Scenario/RangeScan.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Scenario;

use Radiergummi\LaravelRls\Bench\TableSet;
use Radiergummi\LaravelRls\Bench\Variant;

final class RangeScan extends Scenario
{
    public function name(): string
    {
        return 'range_scan';
    }

    public function run(Variant $variant): void
    {
        [$lo, $hi] = [$this->tables->probeRangeLo, $this->tables->probeRangeHi];

        match ($variant) {
            Variant::Floor => $this->db()->select(
                'select * from ' . TableSet::FLOOR . ' where n between ? and ?',
                [$lo, $hi],
            ),
            Variant::Control => $this->db()->select(
                'select * from ' . TableSet::CONTROL . ' where n between ? and ? and tenant_id = ?',
                [$lo, $hi, $this->tables->probeTenantId],
            ),
            Variant::Treatment => $this->rls()->isolateTo(
                ['tenant_id' => $this->tables->probeTenantId],
                fn() => $this->db()->select(
                    'select * from ' . TableSet::TREATMENT . ' where n between ? and ?',
                    [$lo, $hi],
                ),
            ),
        };
    }

    public function explainTarget(): ?array
    {
        return [
            'sql' => 'select * from ' . TableSet::TREATMENT . ' where tenant_id is not null and n between ? and ?',
            'bindings' => [$this->tables->probeRangeLo, $this->tables->probeRangeHi],
            'tenant' => $this->tables->probeTenantId,
        ];
    }
}
```

- [ ] **Step 6: Write Aggregate**

`bench/Scenario/Aggregate.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Scenario;

use Radiergummi\LaravelRls\Bench\TableSet;
use Radiergummi\LaravelRls\Bench\Variant;

final class Aggregate extends Scenario
{
    public function name(): string
    {
        return 'aggregate';
    }

    public function run(Variant $variant): void
    {
        match ($variant) {
            Variant::Floor => $this->db()->select('select count(*) from ' . TableSet::FLOOR),
            Variant::Control => $this->db()->select(
                'select count(*) from ' . TableSet::CONTROL . ' where tenant_id = ?',
                [$this->tables->probeTenantId],
            ),
            Variant::Treatment => $this->rls()->isolateTo(
                ['tenant_id' => $this->tables->probeTenantId],
                fn() => $this->db()->select('select count(*) from ' . TableSet::TREATMENT),
            ),
        };
    }

    public function explainTarget(): ?array
    {
        return [
            'sql' => 'select count(*) from ' . TableSet::TREATMENT,
            'bindings' => [],
            'tenant' => $this->tables->probeTenantId,
        ];
    }
}
```

- [ ] **Step 7: Write Insert**

`bench/Scenario/Insert.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Scenario;

use Illuminate\Support\Str;
use Radiergummi\LaravelRls\Bench\TableSet;
use Radiergummi\LaravelRls\Bench\Variant;

final class Insert extends Scenario
{
    public function name(): string
    {
        return 'insert';
    }

    public function run(Variant $variant): void
    {
        // A write has no WHERE, so floor and control coincide (plain insert). Treatment inserts
        // through the isolated table, exercising WITH CHECK + context injection.
        $row = static fn(): array => [
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'n' => 0,
            'payload' => 'x',
        ];

        match ($variant) {
            Variant::Floor => $this->db()->table(TableSet::FLOOR)->insert(
                ['tenant_id' => $this->tables->probeTenantId] + $row(),
            ),
            Variant::Control => $this->db()->table(TableSet::CONTROL)->insert(
                ['tenant_id' => $this->tables->probeTenantId] + $row(),
            ),
            Variant::Treatment => $this->rls()->isolateTo(
                ['tenant_id' => $this->tables->probeTenantId],
                fn() => $this->db()->table(TableSet::TREATMENT)->insert(
                    ['tenant_id' => $this->tables->probeTenantId] + $row(),
                ),
            ),
        };
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Bench/ScenarioTest.php`
Expected: PASS (1 test).

- [ ] **Step 9: Commit**

```bash
git add bench/Scenario tests/Feature/Bench/ScenarioTest.php
git commit -m "feat: bench scenarios (point-select, range-scan, aggregate, insert)"
```

---

### Task 8: CLI entry, smoke test, and committed baseline

**Files:**
- Create: `bench/run.php`, `bench/baseline.json` (generated)
- Modify: `composer.json` (add `"bench"` script)
- Test: `tests/Feature/Bench/BenchSmokeTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: `composer bench -- [--scale=1k,100k] [--iterations=N] [--warmup=N] [--json=path] [--md=path]` → writes a baseline JSON (default `bench/baseline.json`) and prints the markdown report. `run.php` orchestrates: boot → for each scale seed → for each scenario × variant run `Runner`+`Stats` → amortization probe → `ExplainProbe` for read scenarios → reporters.

- [ ] **Step 1: Add the composer bench script**

In `composer.json` `scripts`, add:

```json
"bench": "php bench/run.php"
```

Run: `composer dump-autoload`
Expected: `Generated autoload files`.

- [ ] **Step 2: Write run.php**

`bench/run.php`:

```php
<?php

declare(strict_types=1);

use Radiergummi\LaravelRls\Bench\Boot;
use Radiergummi\LaravelRls\Bench\Env;
use Radiergummi\LaravelRls\Bench\ExplainProbe;
use Radiergummi\LaravelRls\Bench\Report\JsonReporter;
use Radiergummi\LaravelRls\Bench\Report\MarkdownReporter;
use Radiergummi\LaravelRls\Bench\Runner;
use Radiergummi\LaravelRls\Bench\Schema;
use Radiergummi\LaravelRls\Bench\Scenario\Aggregate;
use Radiergummi\LaravelRls\Bench\Scenario\Insert;
use Radiergummi\LaravelRls\Bench\Scenario\PointSelect;
use Radiergummi\LaravelRls\Bench\Scenario\RangeScan;
use Radiergummi\LaravelRls\Bench\Stats;
use Radiergummi\LaravelRls\Bench\TableSet;
use Radiergummi\LaravelRls\Bench\Variant;

require __DIR__ . '/../vendor/autoload.php';

$opts = getopt('', ['scale::', 'iterations::', 'warmup::', 'json::', 'md::']);
$scales = explode(',', $opts['scale'] ?? '1k,100k');
$iterations = (int) ($opts['iterations'] ?? 2000);
$warmup = (int) ($opts['warmup'] ?? 200);
$jsonPath = $opts['json'] ?? __DIR__ . '/baseline.json';
$mdPath = $opts['md'] ?? null;

$app = Boot::app();
$schema = new Schema($app);
$runner = new Runner();
$db = $app->make('db')->connection();
$rls = $app->make('rls');

$cells = [];
$explain = [];
$amortization = [];

foreach ($scales as $scale) {
    $tables = $schema->seed($scale);

    $scenarios = [
        new PointSelect($app, $tables),
        new RangeScan($app, $tables),
        new Aggregate($app, $tables),
        new Insert($app, $tables),
    ];

    foreach ($scenarios as $scenario) {
        foreach (Variant::cases() as $variant) {
            $samples = $runner->measure(
                static fn(Variant $v) => $scenario->run($v),
                $variant,
                $warmup,
                $iterations,
            );
            $cells[] = [
                'scenario' => $scenario->name(),
                'variant' => $variant->value,
                'scale' => $scale,
                ...Stats::summarize($samples),
            ];
        }

        $target = $scenario->explainTarget();
        if ($target !== null) {
            $probe = $rls->isolateTo(
                ['tenant_id' => $target['tenant']],
                static fn() => ExplainProbe::probe($db, $target['sql'], $target['bindings']),
            );
            $explain[] = ['scenario' => $scenario->name(), 'scale' => $scale, ...$probe];
        }
    }

    // Amortization probe: fixed per-transaction set_config cost = single-query txn - per-query
    // cost inside a 10-query txn. Uses the point-select treatment read.
    $point = new PointSelect($app, $tables);
    $one = Stats::summarize($runner->measure(
        static fn(Variant $v) => $point->run($v),
        Variant::Treatment,
        $warmup,
        $iterations,
    ))['mean_us'];
    $tenPerTxn = Stats::summarize($runner->measure(
        static function (Variant $v) use ($rls, $db, $tables): void {
            $rls->isolateTo(['tenant_id' => $tables->probeTenantId], static function () use ($db, $tables): void {
                $db->transaction(static function () use ($db, $tables): void {
                    for ($q = 0; $q < 10; $q++) {
                        $db->select('select * from ' . TableSet::TREATMENT . ' where id = ?', [$tables->probeRowId]);
                    }
                });
            });
        },
        Variant::Treatment,
        $warmup,
        $iterations,
    ))['mean_us'] / 10.0;
    $amortization[] = [
        'scale' => $scale,
        'per_txn_1_query_us' => $one,
        'per_txn_10_query_us' => $tenPerTxn,
        'derived_fixed_setconfig_us' => max(0.0, $one - $tenPerTxn),
    ];

    $schema->drop();
}

$env = Env::describe(
    (string) $db->selectOne('select version() as v')->v,
    trim((string) shell_exec('git rev-parse --short HEAD')),
    gmdate('Y-m-d\TH:i:s\Z'),
    pgbouncer: false,
    emulatePrepares: (bool) $db->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES),
);

$json = new JsonReporter();
$document = $json->render(
    $env,
    ['iterations' => $iterations, 'warmup' => $warmup, 'scales' => $scales],
    $cells,
    $amortization,
    $explain,
);
$json->write($jsonPath, $document);

$markdown = (new MarkdownReporter())->render($document);
if ($mdPath !== null) {
    file_put_contents($mdPath, $markdown);
}
echo $markdown;
```

- [ ] **Step 3: Write the smoke test**

`tests/Feature/Bench/BenchSmokeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[TestDox('Bench smoke')]
class BenchSmokeTest extends TestCase
{
    #[Test]
    #[TestDox('composer bench runs end-to-end at 1k and writes a valid baseline document')]
    public function bench_runs_and_writes_valid_json(): void
    {
        $json = tempnam(sys_get_temp_dir(), 'bench') . '.json';
        $root = dirname(__DIR__, 3);

        $cmd = sprintf(
            'php %s/bench/run.php --scale=1k --iterations=5 --warmup=2 --json=%s 2>&1',
            escapeshellarg($root),
            escapeshellarg($json),
        );
        exec($cmd, $output, $exit);

        $this->assertSame(0, $exit, "bench failed:\n" . implode("\n", $output));

        $doc = json_decode((string) file_get_contents($json), true);
        $this->assertArrayHasKey('env', $doc);
        $this->assertNotEmpty($doc['cells']);
        $this->assertNotEmpty($doc['explain']);
        $this->assertArrayHasKey('scan_type', $doc['explain'][0]);

        unlink($json);
    }
}
```

- [ ] **Step 4: Run the smoke test**

Run: `vendor/bin/phpunit tests/Feature/Bench/BenchSmokeTest.php`
Expected: PASS (1 test). Fix any wiring in `run.php` until green.

- [ ] **Step 5: Lint, format, and run the whole suite**

```bash
composer lint
composer format
composer test
```

Expected: phpstan clean, pint passed, all tests green (the bench unit + feature tests included; the real bench run is NOT part of the suite).

- [ ] **Step 6: Generate the committed baseline and confirm the index-scan evidence**

```bash
composer bench -- --scale=1k,100k --json=bench/baseline.json
```

Expected: prints the markdown report with a `Headline:` line. Then confirm the shipped predicate is index-backed at scale (the milestone's "done when" evidence):

```bash
php -r '$d=json_decode(file_get_contents("bench/baseline.json"),true); foreach($d["explain"] as $e){ echo "{$e["scenario"]} {$e["scale"]}: {$e["scan_type"]} parallel=".($e["parallel"]?"yes":"no")."\n"; }'
```

Expected: the `range_scan` and `point_select` entries at `100k` show an index/bitmap scan (NOT `Seq Scan`). If `range_scan @ 100k` reports `Seq Scan`, the predicate or index is wrong — stop and investigate before committing.

- [ ] **Step 7: Commit**

```bash
git add composer.json bench/run.php bench/baseline.json tests/Feature/Bench/BenchSmokeTest.php
git commit -m "feat: rls bench CLI, smoke test, and committed baseline.json"
```

---

## Self-Review Notes

- **Spec coverage:** floor/control/treatment + amortization probe (Tasks 6–8, run.php); 4 query shapes × 1k/100k (Tasks 7–8); two clocks — PHP `hrtime` percentiles (Task 3) + DB-side `EXPLAIN` (Task 2, wired Task 8); env-stamped JSON + markdown headline (Tasks 4, 8); dev-only `autoload-dev` (Task 1); Testbench boot with real provider (Task 5); deterministic seeding via `rls_bypass` (Task 6); `Stats`/`ExplainProbe` unit tests + smoke test (Tasks 1, 2, 8); committed `baseline.json` + index-scan evidence (Task 8). Deferred cells (concurrency, 10M, session/boundary/pgbouncer, compound policy, pgbench) are out of scope per Global Constraints and the spec's non-goals.
- **Placeholder scan:** none — every step ships complete code or an exact command. The one API-uncertainty (Testbench `Application::create`) is called out with a concrete first attempt, a vendor file to consult, and `BootTest` as the arbiter.
- **Type consistency:** `Variant` (Floor/Control/Treatment) used identically across Runner/Scenario/run.php; `Stats::summarize` output keys (`*_us`) consumed verbatim by JsonReporter/MarkdownReporter and spread into cells in run.php; `ExplainProbe::parse` output keys (`scan_type`/`parallel`/`exec_ms`) consumed in run.php and the smoke test; `TableSet` constants (`FLOOR`/`CONTROL`/`TREATMENT`) and probe fields used identically in Schema and every Scenario; `Boot::app()` return type consumed uniformly.
- **Known risks to watch during execution:** (1) the Testbench factory API — arbiter is `BootTest` (Task 5). (2) At `1k` the planner may legitimately choose a seq scan (small table); the index-scan assertion is only meaningful at `100k` (Task 8 Step 6), which is why the smoke test at `1k` asserts structure only. (3) Separate-process tests (Tasks 5–7) are required to avoid facade clashes with the Testbench suite; if a runner lacks process isolation support, run those files individually.
