# Milestones — from "proven to work" to "proven good"

The PoC answered *does the idea work?* — yes, now 122 tests against real Postgres
18, including the hard cases (PgBouncer transaction pooling, live `queue:work`, two
real roles, read replicas, foreign connection package). What it has **not**
answered is whether the idea is *good* in the production sense:

- **How much does it cost?** — no performance data at all (Milestone A).
- **Does it actually hold under attack and compounding conditions?** — the current
  suite proves the mechanism on happy paths; it does not try to *break* it (Milestone B).
- **Does it work beyond one Postgres/PHP/Laravel combination?** — only PG 18 / PHP 8.5
  / one Laravel line has ever run (Milestone C).

These three milestones close that gap. Each is larger than a backlog item — it's
a body of work with its own harness/infra. They're largely independent and can run
in parallel; suggested ordering and dependencies are noted at the end.

The existing feature/hardening backlog lives in [`BACKLOG.md`](BACKLOG.md); this
document is the post-PoC, pre-release track.

---

## Status (as of 2026-07-07)

`v0.0.1` is tagged and published on Packagist. The PoC is now at **122 core
tests**; the security work adds more on top (see below).

| Milestone | State | What's done / what's left |
|---|---|---|
| **A — Performance harness** | ✅ **done** | `composer bench`, committed `bench/baseline.json`, per-query + endpoint + latency-sweep cells, `EXPLAIN` evidence, and a README Performance section. |
| **B — Adversarial security suite** | 🚧 **in progress** | `tests/Security/` scaffolded. Written: bypass abuse (cat 2), malicious values (cat 6 + injection value of 3), context-stack integrity (cat 1, infra-free subset), raw-SQL boundary core (cat 3), policy compounding (cat 4 — found + fixed the compound-key macro bug), migration/DDL hazards (cat 7), role/privilege matrix (cat 5). Left: cross-worker leakage (cat 1 infra), covert channels (8). See [`tests/Security/README.md`](../tests/Security/README.md). |
| **C — Version-matrix CI** | 🟡 **partial** | Minimal CI is green (`.github/workflows/ci.yml`: PHP 8.2–8.4 × Postgres 18, PHPStan, Pint). Left: the full PG × PHP × Laravel matrix, `prefer-lowest`, the PgBouncer job, and the perf-regression job. |

---

## Milestone A — Performance & load-testing harness

> **Status: ✅ done.** Harness in `bench/`, baseline committed, results written up
> in the README Performance section.

**Goal:** a data-backed answer to *"what does RLS-via-this-library cost, and is it
fast enough?"* — reported with proper statistics (mean, stddev, and
p50/p90/p95/p98/p99), not a single averaged number.

### The measurement that matters

The honest baseline is **RLS vs. the hand-written scoping it replaces**, on
identical data:

- **Control:** a plain table, no policies, no context injection, queried with an
  explicit `WHERE <key> = ?`.
- **Treatment:** the same table `isolatedBy(<key>)`, context established via
  `Rls::isolateTo(...)`, no manual `WHERE`.

The delta between them *is* the library's cost. Also measure a hard floor (plain
table, no scoping at all) to attribute the cost between "RLS predicate" and
"context-injection round-trip."

### What to isolate

| Cost source | How to measure |
|---|---|
| Per-transaction `set_config` round-trip (transaction strategy) | txn with 1 query vs N queries — amortize the fixed cost |
| Policy predicate evaluation (planner + executor) | `EXPLAIN (ANALYZE, BUFFERS)`; confirm the `STABLE` helper inlines and an index scan is chosen, not a seq scan |
| `WITH CHECK` on writes | insert/update treatment vs control |
| Strategy | `transaction` vs `session` |
| Boundary mode | `wrap` vs `explicit` vs `request` |
| Pooling in the path | direct vs PgBouncer (transaction pooling), varying pool size |
| `PARALLEL SAFE` vs `RESTRICTED` | large parallel scan — resolves the open [BACKLOG](BACKLOG.md) P2 question empirically |

### Scenario matrix

- **Query shapes:** PK point-select, filtered range scan, aggregate/full scan,
  single insert, bulk insert, update, delete, two-table join (both isolated),
  repeated small queries (N+1 shape), large result set.
- **Table scale:** ~1k / 100k / 10M rows — RLS predicate + index behavior changes
  with cardinality.
- **Policy complexity:** single isolation key vs compound (multiple keys) vs
  RESTRICTIVE + permissive base.
- **Concurrency:** 1 / 8 / 32 / 128 concurrent workers — surface `set_config` or
  pool contention; measure throughput (qps), not just latency.
- **Cold vs warm** cache.

### Statistics & reporting

- Fixed iteration count per cell with a discarded warmup; timings via `hrtime(true)`.
- Report **mean, stddev, min, max, p50, p90, p95, p98, p99** for every cell.
- Capture **two clocks**: PHP wall-clock (includes round-trips — what the app feels)
  and DB-side (`EXPLAIN ANALYZE` / `pg_stat_statements` — isolates planner/executor).
- Emit **machine-readable** results (JSON + CSV) plus a rendered markdown report,
  each stamped with environment metadata (PG version, PHP version, CPU, whether
  PgBouncer was in path). Numbers are only comparable *within* one environment —
  record it or the numbers lie.

### Tooling

- A dedicated harness — a console command (`rls:bench`) or standalone script,
  **not** PHPUnit (wrong tool for timing). Deterministic seeded data generator per
  scale tier.
- Consider `pgbench` with custom scripts to measure pure predicate cost independent
  of PHP/PDO overhead, as a cross-check on the PHP-side numbers.
- Mind the measurement traps: pin to a quiet machine, discard warmup, account for
  prepared-statement caching (the `PDO::ATTR_EMULATE_PREPARES` PgBouncer caveat),
  and don't attribute Docker networking latency to RLS.

### Done when

- A published overhead table with percentiles per scenario, and a one-line
  headline the README can cite: *"adds ~X µs p50 / ~Y µs p99 per query and ~one
  round-trip per transaction."*
- The index-scan-able (`STABLE`) claim and the `PARALLEL SAFE` question are
  confirmed with `EXPLAIN` evidence.
- A checked-in baseline JSON that Milestone C can diff against for regression.

---

## Milestone B — Adversarial security & edge-case suite

> **Status: 🚧 in progress.** `tests/Security/` scaffolded with a base case, a
> category map, and stub files. Threat categories 2, 6, 4, 7, 5, the infra-free
> subset of 1, and the core of 3 are written and green; category 8 and the
> infra-dependent parts of 1 and 3 remain. Per-category status lives in
> [`tests/Security/README.md`](../tests/Security/README.md).

**Goal:** a *large* suite that actively tries to break isolation across every edge
case and compounding condition, turning "we believe it holds" into "we tried to
violate every promise and here's what happened." For a security library this is
the milestone that earns trust; where a real leak exists, it must be characterized
and documented, not hidden.

The current tests prove the mechanism on intended paths. This suite is written from
the attacker's side. Organize as a dedicated `tests/Security/` tree, each case
named for the threat and cross-referenced to the design threat model.

### Threat categories

1. **Context leakage across boundaries** — the nightmare cases:
   - Pooling: interleaved transactions, `ROLLBACK`, `SAVEPOINT`/nested transactions,
     deadlock-retry, aborted transactions — context from txn A must never reach txn B.
   - Session strategy: context surviving on a persistent/pooled connection between
     requests/jobs; mid-request `reconnect()`; two distinct connections; skipped flush.
   - Octane long-lived worker: request N's context invisible to request N+1.
   - Queue: job A's context invisible to job B; failed-job **retry**; batched and
     chained jobs; daemon vs `--once`.
   - Stack integrity: nested `isolateTo` / `withoutIsolation` restore correctly on
     exception, nested exception, and deep nesting.

2. **Bypass abuse** (model revised 2026-07-06 — bypass is admin-connection-only in
   both role models; the `rls.bypass()` GUC/clause is gone):
   - Self-escape attempts via `set_config('app.bypass', …)` from any role — must be
     **inert** now (no policy reads it); confirm across every injection vector.
   - The in-flight `RlsManager::isBypassing()` flag must be strictly try/finally-safe:
     an exception (or nested exception) thrown mid-`system()` must not leave the guard
     stuck down for the next query on the same worker.
   - `system()`/`withoutIsolation()` hard-fails closed when `admin_connection` is
     unset — never silently runs unscoped; empty/omitted bypass reason.
   - Admin-connection confinement: app SQL inside a `system()` callback runs on the
     BYPASSRLS connection by design — verify the swap restores on every exit path and
     that work *outside* the callback never lands on the admin connection.

3. **SQL injection & the raw-SQL boundary:**
   - Malicious isolation-key *values* (SQL payloads) — must stay bound params, never
     interpolated into `set_config`.
   - Raw `DB::statement` / `DB::select` / `DB::unprepared` sidestepping the query
     builder — **characterize exactly what the fail-loud guard catches and what leaks**
     (the known-fuzzy [BACKLOG](BACKLOG.md) P1 raw-SQL item; this suite pins its real
     boundary).
   - `SECURITY DEFINER` functions (classic RLS bypass), views, CTEs, subqueries,
     triggers, `COPY`, `TRUNCATE`.

4. **Policy correctness & compounding:**
   - RESTRICTIVE + multiple permissive policies; compound isolation keys with a
     *partial* context set; join where one side is isolated and the other isn't.
   - Cross-tenant foreign keys and unique constraints as **existence oracles**
     (error messages / constraint violations leaking that another tenant's row exists).
   - `withDefault()`: attempt to override the context default, insert NULL, or insert
     a foreign id despite `WITH CHECK`.

5. **Role/privilege matrix:** superuser × BYPASSRLS × owner-no-FORCE × owner-FORCE ×
   restricted, each against read/write/bypass, to state precisely who is confined.
   Include `SET ROLE` mid-session.

6. **Value/type edge cases:** NULL, empty string, `false`/`0`, unicode, whitespace,
   oversized values, type mismatch (int key given a string), and cast failures
   (uuid column vs text context) — every one must fail **closed**, never open.

7. **Migration / DDL:** data migrations under `owner`+FORCE silently touching zero
   rows (the migration auto-bypass backlog item); adding a policy to a table that
   already has data.

8. **Covert channels:** sequence `currval`, `pg_stat_*`, `EXPLAIN` row estimates,
   timing side channels, error-message oracles.

### Done when

- Every promise in the README "Proven in the PoC" table and every threat in the
  design threat model has at least one adversarial test that attempts to violate it.
- The raw-SQL and `SECURITY DEFINER` boundaries are pinned by tests and written up
  as explicit known-limits where they genuinely leak.
- Runs in CI (Milestone C) — it's still PHPUnit, so it rides the matrix for free.

---

## Milestone C — Version-matrix CI (GitHub Actions)

> **Status: 🟡 partial.** `.github/workflows/ci.yml` runs green on every push:
> tests on PHP 8.2–8.4 against a single Postgres 18 service, plus PHPStan and
> Pint. The full matrix below (multiple PG versions, Laravel lines,
> `prefer-lowest`), the PgBouncer job, and the perf-regression job are still to do.

**Goal:** every push proves the library green across the *supported* matrix, not
just the one combination on the author's laptop. This is the substrate that keeps
A and B honest over time.

### Matrix dimensions

| Axis | Candidate values | Notes |
|---|---|---|
| PostgreSQL | 14 · 15 · 16 · 17 · 18 | RLS exists since 9.5. Service container per version. |
| PHP | 8.2 · 8.3 · 8.4 · 8.5 | `composer.json` `^8.2`; dev runs 8.5. |
| Laravel / illuminate | 11 · 12 · 13 | testbench 9 ↔ L11, 10 ↔ L12, 11 ↔ L13. Needs `composer.json` bumped to `^11\|^12\|^13` and testbench to `^9\|^10\|^11`. |
| Dependency resolution | `prefer-lowest` · `prefer-stable` | `prefer-lowest` catches under-constrained deps. |

Prune invalid combinations with matrix `exclude`/`include` (testbench/Laravel are
version-locked, and each Laravel line has its own PHP floor — confirm L13's when
pinning). Use `fail-fast: false` so one red cell doesn't hide the rest.

### Jobs

- **test** (the matrix): Postgres **service container** per version → run
  `tests/bin/setup-db.sh` (needs the superuser the service container provides, to
  create `rls_app` / `rls_restricted` / `rls_test` — the non-superuser roles are
  load-bearing; testing as a superuser makes isolation falsely pass) → `phpunit`.
  Health-check the container before running.
- **pgbouncer** (separate job or added service): bring up PgBouncer so the gated
  `PgBouncerTest` actually runs in CI instead of skipping.
- **static analysis:** `phpstan` on one PHP version (fast).
- **lint:** `pint --test` (format check, non-mutating).
- **perf regression** (depends on Milestone A): run `rls:bench`, diff against the
  checked-in baseline. GitHub runners are noisy — use wide thresholds / directional
  checks only, or a self-hosted runner if stable percentiles are wanted in CI.

### Done when

- `.github/workflows/ci.yml` runs the pruned matrix green on push and PR.
- The PgBouncer test runs (not skipped) in CI.
- README carries status badges; the default branch requires green to merge.

---

## Ordering & dependencies

- **C is the foundation** and is independent — stand up the matrix first so B's new
  tests are exercised everywhere from day one.
- **B is the highest-value milestone** for a security library — it's what justifies
  any "trust this" claim. It rides on C but doesn't need A.
- **A is what answers the adoption question** ("fast enough?"). Independent of B;
  its baseline output feeds C's optional perf-regression job.

A reasonable path: **C → B in parallel with A**. B and A share no code; only C's
perf job depends on A's baseline.
