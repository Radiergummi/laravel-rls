# Performance & load-testing harness — design (Milestone A, v1)

**Status:** approved design, ready for implementation planning. **Scope:** Milestone A of [
`../../MILESTONES.md`](../../MILESTONES.md), first cut. Single-worker latency only. Concurrency/throughput, 10M-row
tier, session/explicit/request variants, PgBouncer-in-path, compound/permissive-base policies, and a `pgbench`
cross-check are deliberately **deferred** and documented as future cells, not dropped.

## Goal

A data-backed, honestly-measured answer to *"what does RLS-via-this-library cost, and is it fast enough?"* — reported
with real statistics (mean, stddev, min, max, p50/p90/p95/p98/p99), a machine-readable baseline Milestone C can diff
against, and `EXPLAIN` evidence for the index-scan and `PARALLEL SAFE` claims. The deliverable includes a one-line
headline the README can cite.

## The measurement that matters

On identical seeded data, three variants of every query shape:

- **floor** — plain table, no policies, no context, query with **no** tenant filter. The
  "no scoping at all" reference.
- **control** — plain table, no policies, explicit `WHERE tenant_id = ?`. The hand-written scoping the library replaces.
- **treatment** — `isolatedBy('tenant_id')` table, context via `Rls::isolateTo([...])`, **no**
  manual `WHERE`. RLS predicate + transaction-local context injection do the work, through the real package path.

`control` and `treatment` return the **same rows**, so:

- **treatment − control = the library's cost** (the headline delta).
- **control − floor = the cost of scoping at all** (the `WHERE`, independent of the library).

For the write scenario (`Insert`) there is no `WHERE`, so **floor and control coincide** (a plain insert); the
meaningful delta is **treatment − control = the `WITH CHECK` evaluation + context injection**. The harness still records
all three variants uniformly; the reporter simply shows floor ≈ control for writes.

A separate **amortization probe** measures treatment at 1 query/transaction vs 10 queries/transaction to isolate the
fixed per-transaction `set_config` round-trip from the per-query predicate cost — the "~one round-trip per transaction"
half of the headline.

## Non-goals (v1)

- No concurrency/throughput (qps) — single worker only. Multi-process runner is a later cell.
- No 10M-row tier — 1k and 100k only (100k is where the seq-scan finding bit).
- No `session` strategy, no `explicit`/`request` boundary — `transaction` + `wrap` only.
- No PgBouncer in the measured path — direct connection only.
- No compound-key or permissive-base policy variants — single isolation key only.
- No `pgbench` cross-check.
- The harness is **dev-only**: PSR-4 under `autoload-dev`, never shipped in the published package, no user-facing
  `rls:bench` command (revisit if users ask).

## Directory layout

```
bench/
  run.php              # entry point (composer bench -- [flags])
  Boot.php             # Testbench app factory: real RlsServiceProvider + two connections
  Schema.php           # deterministic per-scale seed of bench tables (via rls_bypass, committed)
  Scenario/
    Scenario.php       # interface: name(); run(Variant $variant): void  (the single timed op)
    PointSelect.php    # PK point-select
    RangeScan.php      # filtered range scan (index-eligible)
    Aggregate.php      # aggregate over a full scan
    Insert.php         # single insert; treatment exercises WITH CHECK
  Runner.php           # warmup-discard + fixed iterations; collects hrtime(true) samples
  Stats.php            # pure: mean/stddev/min/max/p50/p90/p95/p98/p99
  ExplainProbe.php     # EXPLAIN (ANALYZE, BUFFERS) on treatment reads -> {scanType, parallel, execMs}
  Report/
    JsonReporter.php   # env-stamped baseline JSON
    MarkdownReporter.php  # human overhead table + headline
  baseline.json        # the checked-in reference artifact
```

Namespace: `Radiergummi\LaravelRls\Bench\` → `bench/`, registered only under `autoload-dev`.
`composer.json` gains a `"bench": "php bench/run.php"` script.

## Components & interfaces

Each unit has one purpose, a narrow interface, and (for the pure ones) independent tests.

- **`Boot`** — builds a real Laravel app **once** via Orchestra Testbench's application factory, registers
  `RlsServiceProvider`, and configures `pgsql` (as `rls_app`, owner) and `pgsql_admin`
  (as `rls_bypass`, `BYPASSRLS`), plus `rls.admin_connection => pgsql_admin`. Returns the booted container. Runs outside
  any timed loop, so its cost never enters a sample. *Depends on:*
  Testbench, the package provider. *Fidelity:* treatment queries run through the query builder →
  `RlsPostgresConnection` → transaction-local `set_config`, so numbers reflect real app cost.

- **`Schema`** — for a given scale tier, drops and recreates the three tables and seeds identical deterministic data
  (fixed tenant id set, fixed row distribution — e.g. rows spread across 100 tenants, PRNG seeded from a constant so
  runs are comparable). Seeds through the `rls_bypass`
  connection (bypasses FORCE + `WITH CHECK`), committed so reads see it. Creates the index on the scoping column for
  control and treatment tables (and the plain table for floor) so index behavior is measured, not absent. *Interface:*
  `seed(string $scale): TableSet`,
  `drop(string $scale): void`.

- **`Scenario`** — interface `name(): string` and `run(Variant $variant): void`, where `Variant`
  is an enum `Floor | Control | Treatment`. `run()` performs exactly the one operation to be timed (nothing else — no
  setup, no assertions). Four implementations: `PointSelect`,
  `RangeScan`, `Aggregate`, `Insert`. Treatment `run()` wraps the op in `Rls::isolateTo([...])`; floor/control issue
  plain query-builder calls on the plain table.

- **`Runner`** — for each (scenario, variant, scale) cell: execute `run()` `warmup` times (discarded), then `iterations`
  times bracketed by `hrtime(true)`, collecting one duration sample per iteration. Returns the raw sample array per
  cell. Knows nothing about statistics or reporting. *Interface:*
  `measure(Scenario $s, Variant $v, int $warmup, int $iterations): int[]`
  (nanosecond samples).

- **`Stats`** — pure function over a sample array → `{n, mean, stddev, min, max, p50, p90, p95,
  p98, p99}` (microseconds). Percentiles via nearest-rank on the sorted samples. No I/O. Fully unit-tested against known
  inputs.

- **`ExplainProbe`** — runs `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` on a treatment read once per (scenario, scale),
  parses the plan JSON, and reports `{scanType (e.g. "Index Scan"/"Bitmap
  Index Scan"/"Seq Scan"), parallel: bool, execMs}`. This is the DB-side clock and the evidence for the index-scan /
  `PARALLEL SAFE` claims. The plan-parsing logic is unit-tested against fixture plan JSON.

- **`JsonReporter`** — assembles the env-stamped baseline document (schema below) and writes it. *Interface:*
  `write(array $cells, array $explain, Env $env, string $path): void`.

- **`MarkdownReporter`** — renders a human overhead table (one row per scenario/scale, columns for
  floor/control/treatment p50 & p99 and the treatment−control delta) plus the one-line headline.

- **`run.php`** — parses CLI flags, calls `Boot`, loops scales × scenarios × variants through
  `Schema`/`Runner`/`Stats`, runs `ExplainProbe`, hands results to both reporters. CLI flags:
  `--scale=1k,100k` (comma list), `--iterations=N` (default 2000), `--warmup=N` (default 200),
  `--json=path` (default `bench/baseline.json`), `--md=path` (default stdout).

## Measurement methodology

- **Two clocks.** PHP wall-clock via `hrtime(true)` per iteration (feeds the percentile stats, captures round-trips);
  DB-side via `EXPLAIN (ANALYZE, BUFFERS)` once per read cell (isolates planner/executor, yields scan-type evidence).
- **Warmup & iterations.** Default 200 discarded warmup + 2000 measured iterations per cell, both CLI-overridable.
  Warmup covers prepared-statement caching and plan caching.
- **Prepared statements.** `PDO::ATTR_EMULATE_PREPARES` is pinned to an explicit value and recorded in the env block
  (the PgBouncer/prepared-statement caveat); v1 measures the direct connection so the default stands, but the value is
  captured so future PgBouncer cells are comparable.
- **Determinism.** Data seeded once per scale with a constant PRNG seed; identical rows across floor/control/treatment
  within a scale. The same tenant id is used for every control/treatment read so result-set size is constant.
- **Units.** Nanosecond samples internally; reported in microseconds.

## Output & baseline schema

`bench/baseline.json` (also the shape `JsonReporter` always emits):

```json
{
  "env": {
    "pg_version": "PostgreSQL 18.x ...",
    "php_version": "8.x",
    "uname": "Darwin ... arm64",
    "emulate_prepares": false,
    "pgbouncer": false,
    "git_commit": "a06b861",
    "generated_at": "2026-07-06T12:00:00Z"
  },
  "params": { "iterations": 2000, "warmup": 200, "scales": ["1k", "100k"] },
  "cells": [
    { "scenario": "point_select", "variant": "treatment", "scale": "100k",
      "n": 2000, "mean_us": 0.0, "stddev_us": 0.0, "min_us": 0.0, "max_us": 0.0,
      "p50_us": 0.0, "p90_us": 0.0, "p95_us": 0.0, "p98_us": 0.0, "p99_us": 0.0 }
  ],
  "amortization": [
    { "scale": "100k", "per_txn_1_query_us": 0.0, "per_txn_10_query_us": 0.0,
      "derived_fixed_setconfig_us": 0.0 }
  ],
  "explain": [
    { "scenario": "range_scan", "scale": "100k",
      "scan_type": "Bitmap Index Scan", "parallel": false, "exec_ms": 0.0 }
  ]
}
```

- The `BenchmarkEnvironment` block makes explicit that numbers are only comparable within one environment.
- Milestone C's optional regression job diffs a fresh run's `cells` against committed
  `baseline.json` (threshold-based; the diff tooling itself is Milestone C, not this milestone).
- `MarkdownReporter` renders the same data as a table plus the headline string.

## Testing

- **Unit (ride the normal `phpunit` suite):** `Stats` against known inputs (hand-computed percentiles, stddev, edge
  cases: single sample, all-equal, even/odd counts); `ExplainProbe`'s plan parser against fixture
  `EXPLAIN ... FORMAT JSON` documents (index scan, seq scan, parallel plan → correct `{scanType, parallel, execMs}`).
- **Smoke (one test):** `composer bench -- --scale=1k --iterations=5 --warmup=2 --json=<tmp>`
  exits 0 and writes JSON matching the schema (env + at least one cell + one explain entry).
- The measured bench run itself is **never** part of the `phpunit` suite (wrong tool for timing).

## Verification / done when

- `composer bench` produces `bench/baseline.json` and a markdown table with percentiles per scenario/scale, and a
  one-line headline (*"adds ~X µs p50 / ~Y µs p99 per query and ~one round-trip (~Z µs) per transaction"*).
- The `EXPLAIN` evidence confirms the treatment read is an index scan (not a seq scan) at 100k rows and records the
  `PARALLEL` flag — resolving the index-scan claim empirically for the shipped equality-only predicate.
- A checked-in `bench/baseline.json` exists for Milestone C to diff against.
- `Stats` and `ExplainProbe` parser unit tests + the bench smoke test are green; the full suite stays green.

## Risks & notes

- **Measurement noise on a busy machine.** Mitigated by warmup discard, high iteration count, and reporting stddev +
  full percentiles rather than a single mean. The harness does not pin CPU affinity (out of scope); the README headline
  should be cited as order-of-magnitude, not a contract.
- **Testbench boot fidelity.** The one-time boot must produce a connection that actually routes through
  `RlsPostgresConnection` (the resolver) — the smoke test guards this by asserting a treatment read runs without error
  and the `ExplainProbe` sees the isolation predicate.
- **Deferred cells are real work, not vaporware.** The `Scenario`/`Runner`/`Stats`/reporter boundaries are chosen so
  concurrency, extra scales, and strategy/boundary/pooling variants slot in as new cells/dimensions without reworking
  the core.
