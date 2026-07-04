# Backlog

Remaining work to take `laravel-rls` from validated PoC to a package you'd
publish. Ordered roughly by priority within each group. Each item notes *why*
and *where* it touches the code.

---

## P0 — Correctness & safety hardening (do before any real use)

- [ ] **Production leak canary.** The `InteractsWithRls` test trait asserts an
  empty context stack in teardown; there is no runtime equivalent. Add a
  request/job-start assertion (or a middleware) that the stack is empty, logging
  loudly if not. Highest-severity failure mode is an Octane/worker context leak
  across tenants. *Design §4/§18.* Touches: `RlsManager`, a boot listener for
  Octane `RequestReceived` / queue `JobProcessing`.

- [ ] **Context value validation at `set()` time.** A malformed value (e.g.
  non-UUID) currently reaches the DB and throws on `::uuid`, 500-ing *every*
  query (cluster-wide DoS). Validate against the declared `ContextSchema` type
  when `defineContext()` was used. Touches: `RlsManager::actingAs/set`,
  `ContextSchema`.

- [ ] **Verify against real PgBouncer (transaction pooling).** The PoC proves the
  transaction-local mechanism that *should* work under transaction pooling, but
  never ran against an actual bouncer. Add a docker-compose PgBouncer in
  transaction mode to the test matrix, plus the prepared-statement caveat
  (PgBouncer ≥1.21 or `PDO::ATTR_EMULATE_PREPARES`). *Design §15.*

- [ ] **`session` strategy reset on reconnect + Octane flush.** Session GUCs
  persist on the connection and will leak across requests/jobs without a reset
  hook. Wire connection `reconnect` + Octane request boundaries to re-establish
  or clear. Currently only safe under `transaction` strategy. *Design §16 failure
  modes.* Touches: `HandlesRlsContext`, provider boot.

- [ ] **Loud collision detection for the connection resolver.** If another
  package registers a non-`PostgresConnection` resolver and `connection_class`
  wasn't set to compose with it, throw a clear error instead of silently
  clobbering. *Design §6.* Touches: `RlsServiceProvider::boot`.

- [ ] **Multi-connection / read-replica context.** Context is injected on the
  connection that runs the query; a read replica connection needs the same
  treatment. Confirm and cover. *Design §18.*

---

## P1 — Feature completeness

- [ ] **Publishable app-side `RlsServiceProvider` stub.** The single app-side home
  for `defineContext()` + `resolveContextUsing()` (Fortify/Sanctum pattern).
  Add `rls:install` to publish config + provider + the SQL-functions migration.
  *Design §10.*

- [ ] **`rls:install` / `rls:sync` / `rls:upgrade` commands.** Install SQL helpers
  (migration or `--extension`), regenerate typed helpers from `ContextSchema`,
  and version-migrate `rls.*`. Only `rls:check` and `rls:audit` exist today.
  *Design §8/§12.* Touches: `src/Console`, `RlsFunctions`, `ContextSchema`.

- [ ] **Extension-based install path (`--extension`).** Single-source the SQL and
  emit both the migration and a PGXN extension bundle (`.control` + version
  scripts). For superuser/self-hosted. *Design §8.* Touches: `RlsFunctions`.

- [ ] **`withDefault()` column default.** `scopedBy('tenant_id')->withDefault()`
  → `tenant_id DEFAULT rls.context('tenant_id')::uuid`. Opt-in convenience that,
  with `WITH CHECK`, makes tenant id "impossible to get wrong." *Design §9.*
  Touches: `RlsSchemaMacros`.

- [ ] **`rls.bypass()` semantics per role model, hardened.** Confirm the bypass
  clause is emitted only in `owner` mode and is genuinely inert for the
  restricted role. Add tests that the restricted role cannot self-escape via
  `set_config('app.bypass', ...)`. *Design §5/§7.*

- [ ] **Tenancy-package bridges (stancl / spatie).** Ship a first-class
  `resolveContextUsing(fn () => ['tenant_id' => tenant()?->getKey()])` bridge so
  `laravel-rls` slots in *beneath* existing tenancy packages. *Design §19 — the
  strategic positioning.*

- [ ] **Bypass observability: logging + threshold in `rls:audit`.** `withoutRls()`
  should fire a `RlsBypassed` event and log with the reason; `rls:audit` should
  optionally fail CI over a threshold. Reason string is already required.
  *Design §16.*

- [ ] **Per-table fail-loud granularity for raw SQL.** The guard detects managed
  tables by quoted-name matching in the SQL — fine for the query builder, fuzzy
  for raw SQL. Decide the raw-SQL policy (allowlist? connection-level fallback?).
  *Design §21 open question.* Touches: `HandlesRlsContext::queryTouchesManagedTable`.

---

## P2 — Ergonomics & polish

- [ ] **Earned sugar macros** (`$table->tenantIsolated()`) generated from a
  declared primary scope dimension, rather than only the generic `scopedBy()`.
  *Design §9.*

- [ ] **Richer test assertions** from the design not yet built: `assertVisibleTo`,
  `assertNotVisibleTo`, `assertRlsScoped`, `Rls::explain()` ("why is my result
  empty?" debugger), `dumpRlsPolicies`, `#[RlsContext]` / `#[WithoutRls]`
  attributes, Pest helpers. *Design §13.*

- [ ] **`STABLE` volatility + `PARALLEL SAFE` confirmation.** Helpers are declared
  `STABLE` (index-scan-able). Confirm `PARALLEL SAFE` vs `RESTRICTED` empirically
  on partitioned/parallel scans. *Design §8/§21.*

- [ ] **Nested-transaction tenant-change guard** (`NestedTenantContext` — throw
  when the tenant dimension changes inside an open transaction). *Design §4.*

- [ ] **Migration auto-bypass** (wrap `MigrationsStarted` in `system()`) so data
  backfills under `owner`+FORCE don't silently touch zero rows. *Design §12.*

- [ ] **Package structure**: extract the SQL layer as an extraction-ready module
  (possible future standalone PGXN extension). *Design §3.*

---

## Open design decisions (need a call before hardening)

1. **`wrap` vs `explicit` as the default boundary.** PoC defaults to `wrap`
   (never accidentally unscoped) but `explicit` is less magical. *Design §21 Q1.*
2. **How self-deprecating the README positioning should be** ("maybe use a global
   scope + PHPStan instead"). Currently honest/blunt.
3. **Whether the tenancy-package bridge is a day-one deliverable** vs docs-only.

---

## Known scope limits (documented, not bugs)

- Statement pooling is unsupported by design.
- `owner` mode does not protect against a compromised credential (owner can drop
  policies) — only `restricted` does.
- RLS protects the Postgres query path only — not Scout/search indexes, Redis
  caches, or BI tools connected directly to the database.
