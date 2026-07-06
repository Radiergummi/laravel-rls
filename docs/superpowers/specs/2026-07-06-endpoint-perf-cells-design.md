# Endpoint-level performance cells — design (Milestone A, v2)

**Status:** approved design, ready for implementation planning.
**Extends:** the v1 per-query harness ([`2026-07-06-perf-harness-design.md`](2026-07-06-perf-harness-design.md)),
already merged. This adds **endpoint-level** measurement: what a realistic request — many
standalone queries, not one wrapping transaction — actually costs under each boundary/strategy
config, and how that scales with network latency.

## Why

The v1 per-query numbers show RLS adds ~one transaction round-trip (~0.4ms on localhost) per
**standalone** query under the default `transaction`+`wrap` config. But a real Laravel request runs
many queries *outside* any single transaction — auth, session lookup, and implicit route-model
binding all fire before the route handler. Under `wrap`, each is its own `BEGIN`+`set_config`+`COMMIT`.
The cost is therefore round-trip-bound and multiplies with query count — invisible at localhost and
unaddressed by the per-query cells. This milestone measures the request-level reality and the two
levers that flatten it (the `request` boundary middleware; the `session` strategy).

## What an "endpoint" is

One endpoint = establish RLS context **once** (as auth middleware would), then run **K standalone
queries** (each a separate `select`, no enclosing explicit transaction), measuring total wall-clock.

- **control:** K plain `select`s against a non-RLS table with an explicit `where tenant_id = ?`.
- **treatment:** K RLS-scoped `select`s (context established once; no manual `WHERE`).

`overhead_endpoint = treatment − control`; `overhead_per_query = overhead_endpoint / K`. Fixed scale
**100k** (reusing the v1 seed); **K ∈ {1, 10, 30}** to expose linear-vs-flat scaling.

## The config matrix

Six configs = connection path × strategy/boundary:

| # | connection | strategy | boundary (modeled) | expected |
|---|---|---|---|---|
| 1 | direct | transaction | wrap (default) — each query auto-wraps | ~RTT×round-trips × K (grows with K) |
| 2 | direct | transaction | request — K queries in one txn | ~one added round-trip (flat in K) |
| 3 | direct | session | — standalone queries, GUC set once | ~0 per-query |
| 4 | PgBouncer | transaction | wrap | works (txn-local GUC re-injected per BEGIN) |
| 5 | PgBouncer | transaction | request | works; one txn/request through the pooler |
| 6 | PgBouncer | session | — | **UNSAFE** — see below |

**Config 6 is expected to be unsafe, not merely slow.** Under PgBouncer transaction pooling, a
session GUC set outside a transaction does not persist to the backend the next (auto-commit) query
lands on. The harness runs a **correctness guard** (a scoped count vs the known probe-tenant count);
on mismatch it records `status: "unsafe"` with a note and **no latency comparison**.

### Latency sweep

On the **3 direct configs** (1, 2, 3) at **K=10**, sweep injected network latency
**{0, 1ms±0.3, 5ms±1}** via Toxiproxy, to show the amplification curve (where `wrap`'s per-query
round-trips turn ~0.4ms into multi-ms while `request`/`session` stay flat). Not crossed with
PgBouncer or other K values. Skipped entirely if the Toxiproxy admin API is unreachable.

## Modeling each config

Per config block, `run.php` sets `config(['database.default' => $cfg->connectionName, 'rls.strategy'
=> $cfg->strategy])` so the existing sync-callback + connection-injection machinery applies to the
chosen connection with no special-casing. After the block it restores the default connection, sets
`rls.strategy` back, and calls `resetSessionContext()` + `Rls::forget()` so nothing leaks between
configs.

- **wrap (1, 4):** `strategy=transaction`, boundary default `wrap`. Each standalone `select`
  satisfies `shouldWrapForRls()` (level 0, context present, strategy=transaction, boundary=wrap) →
  auto-wraps its own transaction, injecting context at `BEGIN`.
- **request (2, 5):** `strategy=transaction`, `Endpoint` wraps the K selects in one
  `$conn->transaction(...)`. Inner selects run at level > 0 → no per-query wrap; context injected
  once at the outer `BEGIN`. (Faithful to `RlsRequestTransaction`, which opens one transaction per
  request.)
- **session (3, 6):** `strategy=session`. Establishing context triggers the sync callback →
  `applyRlsContext()`, which under the session strategy writes a session GUC (persists without a
  transaction). Standalone selects then run with no per-query transaction (`shouldWrapForRls()` is
  false because strategy ≠ transaction).

Context is established via `Rls::isolateTo($ctx, fn() => …)` wrapping the K-query run, so it is
always popped afterward.

## Components

New units under `bench/` (dev-only, `autoload-dev`, no `src/` changes):

- **`EndpointConfig`** — `final readonly class` value object: `string $label`, `string
  $connectionName`, `string $strategy` (`'transaction'|'session'`), `bool $oneTransaction`,
  `string $boundaryLabel` (for reporting: `'wrap'|'request'|'—'`). The six configs are instances
  built in `run.php`.

- **`Endpoint`** — `__construct(Application $app, TableSet $tables, int $k)`. Two public methods:
  - `run(EndpointConfig $cfg, string $variant): void` — the single timed operation: for `control`,
    K plain `select`s with manual WHERE; for `treatment`, establish context once and run K selects
    (wrapped in one txn iff `$cfg->oneTransaction`).
  - `treatmentIsCorrect(EndpointConfig $cfg): bool` — establishes context and asserts a scoped count
    equals the known probe-tenant count for this scale; used to flag the unsafe cell. Runs outside
    the timed path.

- **`Toxiproxy`** — tiny admin-API client (`http://127.0.0.1:8474` by default):
  `available(): bool` (GET `/version`), `reset(string $name, string $listen, string $upstream):
  void` (delete-then-create the proxy), `setLatency(string $name, int $ms, int $jitterMs): void`
  (PUT/POST the `latency` toxic), `clear(string $name): void` (remove toxics). Uses Laravel's `Http`
  facade (already a dependency). A pure helper `payload(int $ms, int $jitterMs): array` is
  unit-tested; the network calls are gated/exercised only when the proxy is up.

New infra:

- **`tests/bin/setup-toxiproxy.sh`** — starts a Toxiproxy container exposing the admin API on host
  `127.0.0.1:8474` and a proxy listen port on host `127.0.0.1:5433`. The harness manages the proxy
  named `postgres` (listen inside the container, upstream = the Postgres the container reaches; on
  Docker Desktop that is `host.docker.internal:5432`). The whole latency sweep skips if the admin
  API isn't reachable, mirroring the PgBouncer gate.

Modified:

- **`Boot`** — add two connections: `pgsql_pgbouncer` (host 127.0.0.1, port 6432, `options` with
  `PDO::ATTR_EMULATE_PREPARES => true` for transaction-pooling safety, user `rls_app`) and
  `pgsql_delayed` (host 127.0.0.1, port **5433** — the Toxiproxy proxy listen port, user `rls_app`).
  Both driver `pgsql` so the RLS resolver builds `RlsPostgresConnection`s for them.
- **`run.php`** — after the existing per-query/amortization phases, add an **endpoints phase**:
  iterate the 6 configs × K at localhost (config 6 → correctness guard → `unsafe`/latency), then the
  latency sweep on configs 1–3 at K=10. Endpoint measurements use `Runner`+`Stats` over the whole
  K-query op. Assemble `endpoints` and `latency_sweep` result lists.
- **`Report/JsonReporter`** — accept and emit two new top-level keys: `endpoints`, `latency_sweep`.
- **`Report/MarkdownReporter`** — render an endpoints table, a sweep table, and extend the headline
  with the request-level story.

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

`env` gains `pgbouncer_available: bool` and `toxiproxy_available: bool` so a reader knows which cells
are real vs skipped. Existing keys (`params`, `cells`, `amortization`, `explain`) are unchanged.

## CLI

`run.php` gains no required flags. It honors the existing `--iterations`/`--warmup`; endpoint cells
default to a **lower** iteration count (each op is K queries) — `--endpoint-iterations` (default 200)
and `--endpoint-warmup` (default 20) override it. If PgBouncer / Toxiproxy are unavailable, the
dependent cells are omitted and noted in `env`, never fail the run.

## Testing

- **`tests/Feature/Bench/EndpointTest.php`** (separate process): under the direct configs (1, 2, 3),
  `Endpoint::treatmentIsCorrect()` is true and a treatment run executes without error; the
  PgBouncer configs (4, 5) are asserted only when `:6432` is reachable (correct), and config 6 is
  asserted `unsafe` when reachable (guarded — skipped otherwise). Gating mirrors `PgBouncerTest`.
- **`tests/Unit/Bench/ToxiproxyTest.php`**: `Toxiproxy::payload()` builds the correct latency-toxic
  structure (name, type `latency`, attributes `latency`/`jitter`) — pure, no network.
- **`tests/Feature/Bench/BenchSmokeTest.php`** (extend): the smoke run at `--scale=1k
  --iterations=5 --endpoint-iterations=3` produces a non-empty `endpoints` array with the localhost
  direct configs and the documented keys. The latency sweep is exercised only if Toxiproxy is up.
- The real bench run and any latency-injected cell are never part of the `phpunit` suite.

## Verification / done when

- `composer bench` populates `endpoints` (6 configs × K, config 6 flagged `unsafe` when PgBouncer is
  up) and `latency_sweep` (3 direct configs × {0,1,5}ms when Toxiproxy is up), plus a markdown
  endpoints/sweep table and an updated headline.
- The numbers demonstrate the thesis: `wrap` `overhead_per_query` ≈ flat across K while
  `overhead_endpoint` grows ~linearly; `request`/`session` `overhead_endpoint` ≈ flat across K; the
  latency sweep shows `wrap` overhead scaling with injected RTT while `request`/`session` stay flat.
- `EndpointTest` + `ToxiproxyTest` + extended smoke green; full suite stays green; phpstan + pint
  clean.

## Risks & notes

- **PgBouncer prepared statements.** Transaction pooling requires `PDO::ATTR_EMULATE_PREPARES =>
  true` (or PgBouncer ≥1.21 prepared-statement support). The `pgsql_pgbouncer` connection sets it;
  `emulate_prepares` is already stamped per the v1 env block — record it per-connection if it
  differs.
- **Toxiproxy proxy lifecycle.** `run.php` resets the proxy at sweep start and clears toxics at the
  end (and on the gated path, leaves nothing behind). The proxy's own hop adds a small fixed
  overhead; the sweep runs all three latency points through the same proxy so the only variable is
  the injected toxic.
- **Config leakage.** The per-config `config()` mutation of `database.default`/`rls.strategy` and
  the session GUC MUST be restored/reset after every block (try/finally), or a later config
  measures the wrong thing. This is the highest-risk area and gets explicit teardown + the
  `EndpointTest` correctness guard as a backstop.
- **Jitter and sample count.** Jittered sweep cells have higher variance; endpoint iterations
  default low for speed, but the sweep is about order-of-magnitude amplification, not precise
  percentiles — the report cites `overhead_endpoint` mean, not p99, for sweep cells.
- **Not shipped.** Everything remains dev-only (`autoload-dev`), no `src/` changes, no user-facing
  command.
