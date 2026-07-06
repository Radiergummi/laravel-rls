# Endpoint-level performance cells — design (Milestone A, v2)

**Status:** approved design, ready for implementation planning. **Extends:** the v1 per-query harness ([
`2026-07-06-perf-harness-design.md`](2026-07-06-perf-harness-design.md)), already merged. This adds **endpoint-level**
measurement: what a realistic request — many standalone queries, not one wrapping transaction — actually costs under
each boundary/strategy config, and how that scales with network latency.

## Why

The v1 per-query numbers show RLS adds ~one transaction round-trip (~0.4ms on localhost) per **standalone** query under
the default `transaction`+`wrap` config. But a real Laravel request runs many queries *outside* any single transaction —
auth, session lookup, and implicit route-model binding all fire before the route handler. Under `wrap`, each is its own
`BEGIN`+`set_config`+`COMMIT`. The cost is therefore round-trip-bound and multiplies with query count — invisible at
localhost and unaddressed by the per-query cells. This milestone measures the request-level reality and the two levers
that flatten it (the `request` boundary middleware; the `session` strategy).

## What an "endpoint" is

One endpoint = establish RLS context **once** (as auth middleware would), then run **K standalone queries** (each a
separate `select`, no enclosing explicit transaction), measuring total wall-clock.

- **control:** K plain `select`s against a non-RLS table with an explicit `where tenant_id = ?`.
- **treatment:** K RLS-scoped `select`s (context established once; no manual `WHERE`).

`overhead_endpoint = treatment − control`; `overhead_per_query = overhead_endpoint / K`. Fixed scale **100k**; **K ∈ {1,
10, 30}** to expose linear-vs-flat scaling.

**Seeding:** the v1 per-query phase seeds and then **drops** the bench tables inside its per-scale loop
(`Schema::seed()`/`Schema::drop()`, fixed table names), so no tables survive it. The endpoints phase therefore **seeds
100k itself** (`$schema->seed('100k')`) at its start and drops at its end — it does not reuse v1's tables. This adds one
100k seed (three tables of chunked inserts) to the run.

## The config matrix

Six configs = connection path × strategy/boundary:

| # | connection | strategy    | boundary (modeled)                     | expected                                    |
|---|------------|-------------|----------------------------------------|---------------------------------------------|
| 1 | direct     | transaction | wrap (default) — each query auto-wraps | ~RTT×round-trips × K (grows with K)         |
| 2 | direct     | transaction | request — K queries in one txn         | ~one added round-trip (flat in K)           |
| 3 | direct     | session     | — standalone queries, GUC set once     | ~0 per-query                                |
| 4 | PgBouncer  | transaction | wrap                                   | works (txn-local GUC re-injected per BEGIN) |
| 5 | PgBouncer  | transaction | request                                | works; one txn/request through the pooler   |
| 6 | PgBouncer  | session     | —                                      | **UNSAFE** — see below                      |

**Config 6 is unsafe by construction — flagged, not measured.** Under PgBouncer transaction pooling,
`set_config(..., false)` (the session-strategy write — a function call, not `SET`, issued *outside* any transaction)
runs on whatever backend the pooler hands it, and PgBouncer does **not**
issue `DISCARD ALL` between transactions in this mode. So a *single-client* harness reuses the same backend for the
subsequent scoped count and reads the correct rows — a correctness guard would falsely report `ok`. The fault only
manifests under concurrent pool churn, when a later query lands on a *different* backend that never saw the GUC. The
harness therefore emits config 6 as
`status: "unsafe"` **by construction** (a documented strategy×pooling incompatibility) with an explanatory note and **no
latency comparison** — it does *not* run a single-client guard that cannot observe the fault. An empirical demonstration
needs concurrent clients racing on a churning pool and is **deferred** (out of scope here).

### Latency sweep

On the **3 direct configs** (1, 2, 3) at **K=10**, sweep injected network latency **{0, 1ms±0.3, 5ms±1}** via Toxiproxy,
to show the amplification curve (where `wrap`'s per-query round-trips turn ~0.4ms into multi-ms while `request`/
`session` stay flat). Not crossed with PgBouncer or other K values.

Crucially, the sweep **rebinds each of the three configs onto the `pgsql_delayed` connection**
(`config(['database.default' => 'pgsql_delayed'])`) so their queries actually traverse the Toxiproxy proxy (port 5433)
that carries the injected latency — running them against the direct
`pgsql` (5432) connection would inject **zero** latency and measure nothing. The sweep gates on a **real `select 1`
through the data path** (`pgsql_delayed`), not merely the admin API's `/version`:
if the proxy admin API is unreachable **or** a probe query through the proxy fails, the entire sweep is skipped and
noted in `BenchmarkEnvironment`.

## Modeling each config

Per config block, `run.php` sets `config(['database.default' => $cfg->connectionName, 'rls.strategy'
=> $cfg->strategy])` so the existing sync-callback + connection-injection machinery applies to the chosen connection
with no special-casing. The **entire cell** — the correctness sanity check (direct configs) *and* the timed runs — is
wrapped in **one `try/finally`**. The `finally` tears down in a strict order: first `Rls::forget()`, then
`resetSessionContext()` **on the cell's own connection while `rls.strategy` is still `'session'`** — it is a no-op under
any other strategy (`HandlesRlsContext::resetSessionContext()` early-returns unless the strategy is `session`), so
resetting *after* restoring the strategy would silently leak the session GUC onto that pooled connection — and **only
then** restore `config(['database.default' => …, 'rls.strategy' => …])` to their defaults. This ordering guarantees
nothing leaks between configs even if a run throws.

- **wrap (1, 4):** `strategy=transaction`, boundary default `wrap`. Each standalone `select`
  satisfies `shouldWrapForRls()` (level 0, context present, strategy=transaction, boundary=wrap) → auto-wraps its own
  transaction, injecting context at `BEGIN`.
- **request (2, 5):** `strategy=transaction`, `Endpoint` wraps the K selects in one
  `$conn->transaction(...)`. Inner selects run at level > 0 → no per-query wrap; context injected once at the outer
  `BEGIN`. (Faithful to `RlsRequestTransaction`, which opens one transaction per request.)
- **session (3, 6):** `strategy=session`. Establishing context triggers the sync callback →
  `applyRlsContext()`, which under the session strategy writes a session GUC (persists without a transaction).
  Standalone selects then run with no per-query transaction (`shouldWrapForRls()` is false because strategy ≠
  transaction).

Context is established via `Rls::isolateTo($ctx, fn() => …)` wrapping the K-query run, so it is always popped afterward.

## Components

New units under `bench/` (dev-only, `autoload-dev`, no `src/` changes):

- **`EndpointConfig`** — `final readonly class` value object: `string $label`, `string
  $connectionName`, `string $strategy` (`'transaction'|'session'`), `bool $oneTransaction`,
  `string $boundaryLabel` (for reporting: `'wrap'|'request'|'—'`). The six configs are instances built in `run.php`.

- **`Endpoint`** — `__construct(Application $app, TableSet $tables, int $k)`. Two public methods:
    - `run(EndpointConfig $cfg, string $variant): void` — the single timed operation: for `control`, K plain `select`s
      with manual WHERE; for `treatment`, establish context once and run K selects (wrapped in one txn iff
      `$cfg->oneTransaction`).
    - `treatmentIsCorrect(EndpointConfig $cfg): bool` — establishes context and asserts a scoped count equals the known
      probe-tenant count for this scale. This is a **sanity check that the data path scopes correctly** for the config
      under test, run outside the timed path. It is **not** used to decide config 6's `unsafe` status — a single-client
      guard cannot observe the pooling fault (see the config matrix), so config 6 is flagged unsafe by construction
      instead.

- **`Toxiproxy`** — tiny admin-API client (`http://127.0.0.1:8474` by default):
  `available(): bool` (GET `/version`), `reset(string $name, string $listen, string $upstream):
  void` (delete-then-create the proxy), `setLatency(string $name, int $ms, int $jitterMs): void`
  (PUT/POST the `latency` toxic), `clear(string $name): void` (remove toxics). Uses Laravel's `Http`
  facade (already a dependency). A pure helper `payload(int $ms, int $jitterMs): array` is unit-tested; the network
  calls are gated/exercised only when the proxy is up.

New infra:

- **`tests/bin/setup-toxiproxy.sh`** — starts a Toxiproxy container exposing the admin API on host
  `127.0.0.1:8474` and a proxy listen port on host `127.0.0.1:5433`. The harness manages the proxy named `postgres`
  (listen inside the container, upstream = the Postgres the container reaches; on Docker Desktop that is
  `host.docker.internal:5432`). The whole latency sweep skips if the admin API isn't reachable **or a probe `select 1`
  through the proxy (`pgsql_delayed`) fails** — a real data-path gate, not just the admin API, mirroring the PgBouncer
  gate.

Modified:

- **`Boot`** — add two connections (both driver `pgsql`, so the RLS resolver builds
  `RlsPostgresConnection`s for them):
    - `pgsql_pgbouncer` — host 127.0.0.1, port **6432**, user `rls_app`, **`sslmode => 'disable'`**
      and `options` with `PDO::ATTR_EMULATE_PREPARES => true`, mirroring the working `PgBouncerTest`
      connection exactly (transaction pooling cannot carry server-side prepared statements, and the local PgBouncer
      listener does not offer TLS — `prefer` would work but `disable` matches the proven test).
    - `pgsql_delayed` — host 127.0.0.1, port **5433** (the Toxiproxy proxy listen port), user
      `rls_app`, also `options` with `PDO::ATTR_EMULATE_PREPARES => true` (emulated prepares keep the path uniform and
      avoid prepared-statement surprises across the reset-on-sweep proxy).
- **`run.php`** — after the existing per-query/amortization phases, add an **endpoints phase**:
  seed 100k, then iterate the 6 configs × K at localhost (each cell in one `try/finally`; config 6 emitted `unsafe` by
  construction, the rest timed), then the latency sweep on configs 1–3 at K=10 rebound onto `pgsql_delayed`. Endpoint
  measurements use `Runner`+`Stats` over the whole K-query op. Assemble `endpoints` and `latency_sweep` result lists,
  drop the tables at the end.
- **`Report/JsonReporter`** — accept and emit two new top-level keys: `endpoints`, `latency_sweep`.
- **`Report/MarkdownReporter`** — render an endpoints table, a sweep table, and extend the headline with the
  request-level story.

## Data / output

`baseline.json` gains:

```json
{
  "endpoints": [
    { "label": "direct·transaction·wrap", "connection": "pgsql", "strategy": "transaction",
      "boundary": "wrap", "k": 10, "status": "ok",
      "control_us": 0.0, "treatment_us": 0.0, "overhead_endpoint_us": 0.0, "overhead_per_query_us": 0.0 },
    { "label": "pgbouncer·session", "connection": "pgsql_pgbouncer", "strategy": "session",
      "boundary": "—", "k": 10, "status": "unsafe",
      "note": "session GUC does not survive PgBouncer transaction pooling" }
  ],
  "latency_sweep": [
    { "label": "direct·transaction·wrap", "k": 10, "injected_ms": 0, "jitter_ms": 0,
      "control_us": 0.0, "treatment_us": 0.0, "overhead_endpoint_us": 0.0 }
  ]
}
```

`BenchmarkEnvironment` already carries `pgbouncer: bool`; it gains a matching **`toxiproxy: bool`**
(both are plain availability flags — keep the existing key name, no `_available` suffix) so a reader knows which cells
are real vs skipped. `describe()` takes the two booleans; `run.php` passes
`pgbouncer:` from a `:6432` reachability probe (replacing today's hardcoded `false`) and
`toxiproxy:` from the data-path gate above. Existing document keys (`params`, `cells`,
`amortization`, `explain`) are unchanged.

## CLI

`run.php` gains no required flags. It honors the existing `--iterations`/`--warmup`; endpoint cells default to a
**lower** iteration count (each op is K queries) — `--endpoint-iterations` (default 200)
and `--endpoint-warmup` (default 20) override it. The **200 default is load-bearing**: each endpoint op already bundles
K queries, so 200 iterations buys a stable mean without ballooning wall-clock; dropping it much lower (as the smoke test
does) yields noisy means fit only for a structural smoke-check, not for the committed baseline. If PgBouncer / Toxiproxy
are unavailable, the dependent cells are omitted and noted in `BenchmarkEnvironment`, never fail the run.

## Testing

- **`tests/Feature/Bench/EndpointTest.php`** (separate process): under the direct configs (1, 2, 3),
  `Endpoint::treatmentIsCorrect()` is true and a treatment run executes without error; the PgBouncer configs (4, 5) are
  asserted only when `:6432` is reachable (`treatmentIsCorrect()` true, runs execute). Config 6 is asserted to be
  emitted with `status: "unsafe"` **by construction** (the harness marks it without running a single-client guard) — the
  test does **not** try to observe the pooling fault single-client. Gating mirrors `PgBouncerTest`.
- **`tests/Unit/Bench/ToxiproxyTest.php`**: `Toxiproxy::payload()` builds the correct latency-toxic structure (name,
  type `latency`, attributes `latency`/`jitter`) — pure, no network.
- **`tests/Feature/Bench/BenchSmokeTest.php`** (extend): the smoke run at `--scale=1k
  --iterations=5 --endpoint-iterations=3` produces a non-empty `endpoints` array with the localhost direct configs and
  the documented keys. The latency sweep is exercised only if Toxiproxy is up.
- The real bench run and any latency-injected cell are never part of the `phpunit` suite.

## Verification / done when

- `composer bench` populates `endpoints` (6 configs × K, config 6 emitted `unsafe` by construction when PgBouncer is up)
  and `latency_sweep` (3 direct configs rebound onto `pgsql_delayed` × {0,1,5}ms when Toxiproxy is up), plus a markdown
  endpoints/sweep table and an updated headline.
- The numbers demonstrate the thesis: `wrap` `overhead_per_query` ≈ flat across K while
  `overhead_endpoint` grows ~linearly; `request`/`session` `overhead_endpoint` ≈ flat across K; the latency sweep shows
  `wrap` overhead scaling with injected RTT while `request`/`session` stay flat.
- `EndpointTest` + `ToxiproxyTest` + extended smoke green; full suite stays green; phpstan + pint clean.

## Risks & notes

- **PgBouncer prepared statements.** Transaction pooling requires `PDO::ATTR_EMULATE_PREPARES =>
  true` (or PgBouncer ≥1.21 prepared-statement support). The `pgsql_pgbouncer` connection sets it;
  `emulate_prepares` is already stamped per the v1 env block — record it per-connection if it differs.
- **Toxiproxy proxy lifecycle.** `run.php` resets the proxy at sweep start and clears toxics at the end (and on the
  gated path, leaves nothing behind). The proxy's own hop adds a small fixed overhead; the sweep runs all three latency
  points through the same proxy so the only variable is the injected toxic.
- **Config leakage.** The per-config `config()` mutation of `database.default`/`rls.strategy` and the session GUC MUST
  be restored/reset after every block, or a later config measures the wrong thing. This is the highest-risk area: the
  whole cell runs inside one `try/finally`, and the
  `finally` **resets the session context while the strategy is still `session`** (else the reset is a silent no-op)
  *before* restoring `database.default`/`rls.strategy` — see "Modeling each config". The `EndpointTest` direct-config
  sanity check is the backstop for the direct path; config 6's unsafety is asserted by construction, not by a
  single-client guard.
- **Jitter and sample count.** Jittered sweep cells have higher variance; endpoint iterations default low for speed, but
  the sweep is about order-of-magnitude amplification, not precise percentiles — the report cites `overhead_endpoint`
  mean, not p99, for sweep cells.
- **Not shipped.** Everything remains dev-only (`autoload-dev`), no `src/` changes, no user-facing command.
