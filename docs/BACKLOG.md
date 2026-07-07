# Backlog

Remaining work to take `laravel-rls` from validated PoC to a package you'd
publish. Ordered roughly by priority within each group. Each item notes *why*
and *where* it touches the code.

> Post-PoC, pre-release tracks (performance harness, adversarial security suite,
> version-matrix CI) live in [`MILESTONES.md`](MILESTONES.md).

---

## P0 — Correctness & safety hardening (do before any real use)

- [x] **Owner-mode bypass clause forced a sequential scan — bypass unified onto an
  admin connection.** The old owner isolation predicate
  `rls.bypass() or "<col>" = rls.context('<key>')::<type>` could not use the
  scoping-column index: the `OR rls.bypass()` forced a **Seq Scan** on every scoped
  read (~26.6 ms mean vs ~0.8 ms index-friendly at 100k rows — ~30× slower, growing
  linearly with table size). In-band bypass under FORCE is impossible
  (`SET LOCAL row_security = off` errors), so the `OR` was the only in-band bypass —
  and it was the sole cause of the seq scan.

  **Resolution (2026-07-06, [`plan`](superpowers/plans/2026-07-05-owner-mode-bypass-unification.md)).**
  The isolation predicate is now **equality-only** in both role models
  (`"<col>" = rls.context('<key>')::<type>`), and `rls.bypass()` is dropped
  entirely. Bypass (`system()`/`withoutIsolation()`) routes to a privileged
  `BYPASSRLS` admin connection unconditionally — the machinery restricted mode
  already used — and hard-fails with `AdminConnectionRequired` when no
  `admin_connection` is configured. Owner mode's zero-infra in-band bypass is gone
  (accepted breaking change). Verified against real PG 18: the shipped predicate is
  a **Bitmap Index Scan** (`OwnerModeBypassTest`, `PolicyDslTest`), scoped reads and
  fail-closed and `WITH CHECK` all hold, and `RlsContext` is now a pure value bag.
  Touched `RlsSchemaMacros::isolatedBy`, `RlsFunctions`, `HandlesRlsContext`,
  `RlsManager` (in-flight `isBypassing()` flag), `RlsContext`, `RlsServiceProvider`.
  *Design §5/§7/§8. Found 2026-07-05 via the perf harness spike (Milestone A).*

- [x] **Production leak canary.** `RlsManager::checkForLeak()` clears any stale
  context and surfaces it per config `leak_canary` (`log` default | `throw` |
  `off`). Wired to Octane `RequestReceived` and queue `Looping` — deliberately
  *not* `JobProcessing`, which fires after Laravel hydrates the job's own context
  and would flag every job as a leak; `Looping` fires between jobs before
  hydration (`--once` is a fresh process, needs no check). *Design §4/§18.*

- [x] **Context value validation at `set()` time.** `RlsManager::push()`
  validates every value against the declared `ContextSchema` type via
  `ContextSchema::matches()`, throwing `InvalidContextValue` in PHP before a
  malformed value can reach the DB. No-op when `defineContext()` wasn't used or
  for undeclared dimensions. *Design §4/§18.*

- [x] **Verify against real PgBouncer (transaction pooling).** `PgBouncerTest`
  runs the transaction strategy through a real PgBouncer (1.25) in transaction
  pooling mode: context reaches queries at each BEGIN, each transaction gets its
  own context regardless of which pooled backend serves it, and nothing leaks
  once context is inactive. Uses `PDO::ATTR_EMULATE_PREPARES` for the
  prepared-statement caveat. Gated — skipped unless 127.0.0.1:6432 is reachable;
  bring it up with `tests/bin/setup-pgbouncer.sh`. *Design §15.*

- [x] **`session` strategy reset on reconnect + Octane flush.**
  `HandlesRlsContext::resetSessionContext()` blanks the persistent session GUCs;
  the provider calls it on each worker boundary (queue `Looping`, Octane
  `RequestReceived`) via `flushSessionContext()` — catching the case the leak
  canary can't (empty scoped stack but live GUC on the pooled connection). An
  overridden `reconnect()` re-establishes context on a fresh backend so it isn't
  silently lost. *Design §16 failure modes.*

- [x] **Loud collision detection for the connection resolver.**
  `RlsServiceProvider::registerConnectionResolver()` throws `ResolverCollision`
  when a foreign pgsql resolver is already registered and `connection_class` is
  still the default (a silent clobber), guiding the user to compose instead. Our
  own resolver is tracked by identity so re-registration isn't a false positive.
  *Design §6.*

- [x] **Multi-connection / read-replica context.** Under the session strategy a
  read/write-split connection's read PDO (the replica, used by plain SELECTs
  outside a transaction) is now given the same GUCs as the write PDO
  (`HandlesRlsContext::setConfig` mirrors to the read PDO when distinct). The
  transaction/wrap strategy already covers any connection via `beginTransaction`
  injection. *Design §18.*
  - *Residual:* a **separate named** replica connection under the session
    strategy isn't synced (the sync callback only touches the default connection,
    and secondary connections resolve lazily). Fine under transaction/wrap.

- [x] **`tenant_id` assumptions hard-coded.** Some facilities like 
  `\Radiergummi\LaravelRls\Testing\InteractsWithRls` assume the `tenant_id`
  dimension is always present, while the library must never make any assumptions
  about the schema or tenancy layout.
  Resolved via the isolation-vocabulary unification (2026-07-05): trait has no
  `tenant_id` literal; all isolation keys are explicit.

---

## P1 — Feature completeness

- [x] **Publishable app-side `RlsServiceProvider` stub.** `rls:install` publishes
  the config, the SQL-functions migration (`publishesMigrations`), and an
  app-side `RlsServiceProvider` stub (the single home for `defineContext()` +
  `resolveContextUsing()`), then prints next steps. Publish groups: `rls-config`,
  `rls-migrations`, `rls-provider`. *Design §10.*

- [~] **`rls:install` / `rls:sync` / `rls:upgrade` commands.** `rls:install` and
  `rls:sync` done (`rls:sync` regenerates the typed `rls.<dim>()` helpers from the
  declared `ContextSchema`). Still to do: `rls:upgrade` (version-migrate `rls.*`).
  *Design §8/§12.*

- [ ] **Extension-based install path (`--extension`).** Single-source the SQL and
  emit both the migration and a PGXN extension bundle (`.control` + version
  scripts). For superuser/self-hosted. *Design §8.* Touches: `RlsFunctions`.

- [x] **`withDefault()` column default.** `isolatedBy('tenant_id')->withDefault()`
  sets the scoping column's default to `rls.context('tenant_id')::uuid`, so an
  insert that omits it is auto-filled from context. `isolatedBy()` now returns a
  fluent `IsolatedByDefinition`. With `WITH CHECK`, makes the scope id "impossible
  to get wrong." *Design §9.*

- [x] **Bypass semantics hardened, then unified (2026-07-06).** Originally tested
  the owner-mode `rls.bypass()` clause; **superseded by the bypass unification**
  (see the resolved P0 item above). The predicate is now equality-only in both
  models with no in-band clause, so a role setting `app.bypass='on'` directly is
  inert — confirmed by `RestrictedIsolationTest` and covered end-to-end by
  `OwnerModeBypassTest`. *Design §5/§7.*

- [~] **Tenancy-package bridges (stancl / spatie).** Docs-only recipe shipped in
  the README ("Using beneath a tenancy package") per the resolved design decision.
  A first-class bridge (auto-wiring their tenant-initialized events) can follow.
  *Design §19 — the strategic positioning.*

- [x] **Bypass observability: logging + threshold in `rls:audit`.** `withoutIsolation()`
  fires an `RlsBypassed` event (carrying the reason); the provider logs each at
  `notice`. `rls:audit --threshold=N` exits 1 when the bypass call-site count
  exceeds N. *Design §16.*

- [ ] **Per-table fail-loud granularity for raw SQL.** The guard detects managed
  tables by quoted-name matching in the SQL — fine for the query builder, fuzzy
  for raw SQL. Decide the raw-SQL policy (allowlist? connection-level fallback?).
  *Design §21 open question.* Touches: `HandlesRlsContext::queryTouchesManagedTable`.

---

## P2 — Ergonomics & polish

- [ ] **Earned sugar macros** (`$table->tenantIsolated()`) generated from a
  declared primary scope dimension, rather than only the generic `isolatedBy()`.
  *Design §9.*

- [~] **Richer test assertions** from the design. Done: `assertVisibleTo` /
  `assertNotVisibleTo` (subset semantics — asserts given model keys are / are not
  visible under a context; `TenantIsolationTest`). Still to do: `assertRlsScoped`,
  `Rls::explain()` ("why is my result empty?" debugger), `dumpRlsPolicies`,
  `#[RlsContext]` / `#[WithoutRls]` attributes, Pest helpers. *Design §13.*

- [x] **`STABLE` volatility + `PARALLEL SAFE` confirmation.** Helpers are declared
  `STABLE` (index-scan-able). `rls.context()` is now also `PARALLEL SAFE` —
  Postgres derives a query's parallel-safety from the RLS policy's *declared*
  function, so the `CREATE FUNCTION` default (`PARALLEL UNSAFE`) was silently
  forcing a serial plan on every isolated table. Confirmed against real PG 18 in
  `ParallelSafetyTest`: a forced-parallel scan parallelizes and stays correctly
  scoped. *Design §8/§21.*

- [x] **Nested-transaction tenant-change guard** (`NestedTenantContext` — throw
  when an isolation key changes to a different value inside an open transaction).
  Opt-in via `rls.on_nested_change = 'throw'` (default `'allow'`): the standard
  `RefreshDatabase`/`DatabaseTransactions` harness wraps every test in one
  transaction, so an always-on guard would trip the normal "seed as A, assert as
  B" pattern; production code under the default `wrap` boundary never hits it
  either (no open transaction between queries → nesting is at txn level 0). It
  fires only for genuine cross-scope work inside an explicit transaction.
  `NestedTransactionGuardTest`; `push()` rolls the frame back on a rejected sync
  so the stack stays clean. *Design §4.*

- [ ] **Migration auto-bypass** (wrap `MigrationsStarted` in `system()`) so data
  backfills under `owner`+FORCE don't silently touch zero rows. *Design §12.*

- [ ] **Package structure**: extract the SQL layer as an extraction-ready module
  (possible future standalone PGXN extension). *Design §3.*

---

## Open design decisions — RESOLVED (2026-07-04)

1. **`wrap` vs `explicit` default boundary** → **keep `wrap`** (secure-by-default;
   already the default, no change). *Design §21 Q1.*
2. **README positioning tone** → **keep honest/blunt** ("here's when NOT to use
   this").
3. **Tenancy-package bridge** → **docs-only recipe first**; a first-class
   stancl/spatie bridge can follow later.

---

## Known scope limits (documented, not bugs)

- Statement pooling is unsupported by design.
- `owner` mode does not protect against a compromised credential (owner can drop
  policies) — only `restricted` does.
- RLS protects the Postgres query path only — not Scout/search indexes, Redis
  caches, or BI tools connected directly to the database.
