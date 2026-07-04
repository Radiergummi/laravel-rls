# Laravel PostgreSQL Row-Level Security — Design

**Package:** `radiergummi/laravel-rls`
**Date:** 2026-07-04
**Status:** Design (pre-PoC). No code yet.

---

## 1. What this is, and when *not* to use it

A Laravel package that makes PostgreSQL Row-Level Security (RLS) a first-class,
ergonomic feature — so the database itself becomes a tenant-isolation
enforcement layer, and a forgotten `WHERE tenant_id = …` cannot leak data.

Developers set context through a Laravel-native API (`Rls::actingAs(...)`),
never through GUCs, `SET LOCAL`, or `set_config()`. Policies reference
transaction-local context via helper functions (`rls.tenant_id()`), mirroring
the ergonomics of Supabase's `auth.uid()`.

### Honest positioning (read this first)

This package is **not the default choice for every multi-tenant SaaS.** A base
model with a global tenant scope plus a PHPStan/Larastan rule that fails CI on
any unscoped query against a tenant table gives you ~80% of the safety at ~5% of
the cost, with **zero** round-trip overhead and **zero** connection-pooling
entanglement. RLS's marginal value over that is narrow and specific:

1. It survives **raw SQL / query-builder paths** that bypass Eloquent scopes.
2. It survives **bugs in the scope itself**.
3. In `restricted` mode, it survives a **live attacker** — even a successful SQL
   injection cannot cross tenants, because the injected query still runs as the
   RLS-bound role. **This is the single strongest reason to adopt the package.**

If none of those three justify the cost for your app, use the global scope and
do not install this.

**"Final enforcement layer" is a phrase we deliberately avoid.** RLS protects
the PostgreSQL query path only. Search indexes (Scout/Meilisearch/Algolia),
Redis caches, read replicas, and BI tools connected directly to the database are
all separate data paths RLS never touches. This package is *a* layer on *one*
path — not a completeness guarantee.

---

## 2. Design principles

- **Secure by default, fully customizable, never forced.** Every safe default
  (RESTRICTIVE policies, `USING` + `WITH CHECK`, FORCE in owner mode, fail-loud)
  is overridable; none is mandatory infrastructure.
- **Blesses no auth model.** Context is a generic named-dimension map. The
  package ships zero opinions about "tenant" / "user" / "role"; those are the
  app's words, declared by the app.
- **Fail loud in the app, fail closed in the database.** Missing context throws
  in PHP before hitting the DB; if it slips through, the policy returns zero rows
  rather than leaking.
- **Bypass is visible.** Bypassing RLS is easy-when-scoped and
  logged/audited-always, because fail-closed design creates pressure toward
  bypass, and unaudited bypass is worse than no RLS.
- **Composes with the ecosystem.** First-class interop with other `pgsql`
  connection packages (tpetry) and with tenancy packages (stancl, spatie) — we
  are a layer *beneath* them, not a competitor.

---

## 3. Architecture — one package, modular internals

**Single Composer package**, not a `-core` / `-http` split. The components
(context manager, connection layer, DSL, HTTP/queue integration, testing) are
conceptually separable but not *independently releasable* — they version and
change together, and for a security package one auditable, atomically-versioned
unit is a feature. This mirrors Laravel's own first-party packages (Sanctum,
Fortify, Cashier).

Composability comes from **config + conditional registration**, not package
boundaries:

- Clean internal namespaces: `Context`, `Database`, `Schema`, `Http`, `Queue`,
  `Console`, `Testing`.
- The package `RlsServiceProvider` registers integrations conditionally: the
  middleware is an available alias pushed to no group by default; queue/Octane
  listeners auto-register but are inert without context; console commands bind
  only under `runningInConsole()`.
- Publishes: `config/rls.php`, an **app-side** `RlsServiceProvider` stub, and
  migrations.

**The SQL layer is kept extraction-ready** (cleanly bounded) so it *could* later
graduate into a standalone PGXN extension usable outside Laravel — but it is not
extracted preemptively.

---

## 4. Context abstraction

Built on Laravel's `Illuminate\Support\Facades\Context` (**Laravel 11+
baseline**), which gives us automatic job propagation, per-request/per-job reset,
and dehydrate/hydrate hooks for free.

- **`RlsContext`** — immutable value object; an ordered map of named string keys
  → scalar/JSON values, each backed by a GUC (`app.<key>`, prefix configurable).
  **Scalars only** (ids, not Eloquent models) → serialization-clean by
  construction.
- **`RlsManager`** — container singleton holding a **stack** of contexts. `Rls`
  is a facade over it. The connection layer reads `Rls::current()` (top of stack)
  when injecting `set_config`.

### Why a stack

Every interesting operation nests: impersonation inside a request, a `system()`
bypass inside a tenant scope, a job that restores context then does an admin
lookup. Push on enter, pop on exit (in a `finally`). Empty stack = no context =
fail-loud guard fires.

### Two ergonomics levels (agnostic ≠ clumsy)

- **Ad-hoc (zero config):** `Rls::actingAs(['tenant_id' => $t->id], fn () => …)`
- **Declared dimensions (opt-in sugar):** declare once, get typed PHP accessors
  *and* generated typed SQL helpers:
  ```php
  Rls::defineContext(function (ContextSchema $c) {
      $c->uuid('tenant_id');
      $c->integer('user_id')->nullable();
      $c->json('roles');
  });
  // generates: Rls::tenantId()  and  SQL rls.tenant_id()
  ```

### Identity resolution

`actingAs` accepts `int|string|Model`. A scalar is used verbatim; a Model
resolves via `getKey()`, or via an opt-in `RlsIdentifiable` interface
(`rlsIdentifier(): int|string`).

### Reset boundaries (load-bearing safety)

Two layers of defense against leakage:
- **DB layer:** transaction-local context evaporates at COMMIT/ROLLBACK.
- **PHP layer:** the manager hard-resets the stack on every runtime boundary —
  Octane `RequestReceived`/`RequestTerminated`/`TaskReceived`/`TickReceived`,
  queue `JobProcessing`/`JobProcessed`/`JobFailed`; naturally fresh per FPM
  request and per Artisan invocation.
- **Production leak canary:** assert the context stack is empty at request/job
  start; a non-empty stack is a leak-shaped bug and is reported.

### Value validation

Context values are validated at `set()` time (against the declared dimension
type, when declared). This prevents a malformed value from reaching the DB and
throwing on `rls.tenant_id()::uuid` — which would otherwise 500 *every* query
(a cluster-wide DoS from one bad value).

---

## 5. Context establishment — the keystone

**The unit of context is the transaction.** Under "must support all pooling
modes," the mechanics decide this for us.

### Mechanism: `set_config()` with a bound parameter, always

| Mechanism | Lifetime | Survives txn pooling? | Injection-safe? |
|---|---|---|---|
| `SET app.tenant = '...'` | session | ❌ | ❌ **`SET` cannot be parameterized** |
| `set_config('app.tenant', $1, false)` | session | ❌ | ✅ |
| `set_config('app.tenant', $1, true)` | **transaction** | ✅ | ✅ |

`SET` cannot take parameters, forcing string interpolation of the tenant id into
SQL — an injection seam in the security layer. Therefore the package uses
**`set_config()` with a bound parameter as the only code path**, never a
string-built `SET LOCAL`.

### Strategies

- **`transaction` (default):** inject `set_config(..., true)` as the first
  statement inside each transaction. Correct on every topology except statement
  pooling — including direct connections. **Fail-safe:** context evaporates at
  transaction end, so it can never leak across requests/jobs/Octane workers.
- **`session` (opt-in performance):** `set_config(..., false)` once per
  context-change. Cheaper at steady state, but only safe on direct/session-pooled
  connections **and** requires a reset hook (Octane flush, reconnect, worker
  recycle). The package **refuses** to combine `session` with a
  transaction-pooled connection.
- **Statement pooling:** explicitly unsupported (documented; detected and
  refused where possible).

### Boundary policy (`rls.boundary`)

Controls how context reaches queries that Laravel runs *outside* an explicit
transaction:

- **`wrap` (default):** self-wrap a query in a transaction — but **only** when
  (a) a context is set **and** (b) the query targets an RLS-managed table. Bare
  queries on non-tenant/exempt tables are never silently wrapped. Every wrap
  fires a `RlsQueryWrapped` event (debug-loggable): the magic is bounded and
  observable.
- **`explicit`:** a context-bearing query on an RLS table outside a transaction
  throws `MissingContextBoundary`. Zero magic.
- **`request`:** the opt-in per-request-transaction middleware provides the
  boundary.

### Missing-context semantics

- **DB:** `tenant_id = rls.tenant_id()` with no context → compares to NULL →
  zero rows (fail-closed, never a leak).
- **PHP:** a query targeting an RLS-managed table with no context set, outside a
  `system()`/bypass scope, throws `MissingTenantContext` *before* hitting the DB
  (fail-loud). Granularity is **per-table** for query-builder calls; raw SQL
  falls back to connection-level "is any context set" (to be refined post-PoC).

---

## 6. Laravel interception

**Seam: a custom connection class via `Connection::resolverFor('pgsql', …)`** —
Laravel's documented extension point; no monkey-patching. RLS behavior lives in a
**`HandlesRlsContext` trait**, not a fixed class, so it composes.

Two overrides do the work:

1. **`beginTransaction()`** — after `parent::beginTransaction()`, if
   `transactionLevel === 1` (a real BEGIN, not a savepoint) and context is
   present, emit `select set_config('app.<key>', ?, true)` (bound) for each
   dimension. Inject **only at level 1**; nested savepoints inherit the context,
   and `ROLLBACK TO SAVEPOINT` never strips it.
2. **`run()`** — the single funnel for all queries. Applies the boundary policy
   (§5): wrap / throw / pass-through.

**Mid-transaction re-inject:** when context is set *while a transaction is
already active*, immediately run `set_config` for the current context. This
powers impersonation inside a transaction **and is what makes testing work**
under `RefreshDatabase` (which opens the wrapping transaction before the test
body sets context).

**Changing the tenant dimension inside an open transaction throws**
(`NestedTenantContext`) by default — writing two tenants' rows in one atomic unit
is almost always a bug. A `system()` bypass inside a transaction stays legal.

**DDL/maintenance escape:** statements that cannot run in a transaction
(`CREATE INDEX CONCURRENTLY`, `VACUUM`) have an explicit never-wrap path. In
practice they run without context anyway.

### Composability with other connection packages

Only one resolver can win, so naive registration would silently clobber packages
like `tpetry/laravel-postgresql-enhanced`. Mitigations:

- **Configurable base class + trait.** `RlsPostgresConnection` is just
  `PostgresConnection` + `HandlesRlsContext`. To stack on tpetry:
  ```php
  class RlsEnhancedConnection extends PostgresqlEnhancedConnection {
      use HandlesRlsContext;
  }
  // config/rls.php → 'connection_class' => RlsEnhancedConnection::class
  ```
  Ready-made composed classes shipped for popular packages.
- **Loud collision detection:** if a non-`PostgresConnection` resolver is already
  registered and we'd overwrite it, throw pointing at `connection_class` — never
  silently clobber.
- **Lite mode (subclass-free):** transaction injection can use the
  `TransactionBeginning` event (fires after PDO `BEGIN`, guarded on
  `transactionLevel === 1`), giving zero-connection-override compatibility with
  any connection package — at the cost of losing transparent bare-query wrapping
  (`explicit` boundary only).

Schema macros (`Blueprint::macro`) are additive; the one caveat to verify is a
tpetry `Blueprint` subclass re-using `Macroable` (then register macros on both).

**Goal:** tpetry is in the CI test matrix.

---

## 7. Role & enforcement model

RLS is silently skipped for a table's **owner** unless `FORCE ROW LEVEL
SECURITY`, and an owner can always `DISABLE RLS` / `DROP POLICY`. So the role
model decides whether enforcement is *real*. One config key, `rls.role_model`,
drives migration defaults and the bypass mechanism.

### `owner` (zero-infra default)

App keeps its single credential (owns the tables). `tenantIsolated()` emits
`ENABLE` **and** `FORCE`. One-line adoption for existing apps.

**Honest limitation, stated loudly:** owner mode protects against *application
logic bugs and injection in tenant queries*, **not** against a fully compromised
credential (the owner can drop policies). `rls:check`/install emit a prominent
warning: *owner mode stops your developers, not an attacker.*

### `restricted` (recommended for real isolation)

A privileged owner role runs migrations (`pgsql_admin` connection); the app
connects as a separate **non-owner, non-`BYPASSRLS`** role that RLS genuinely
constrains and that cannot disable policies or escalate. FORCE is unnecessary
(and undesirable — the owner should move freely during backfills).

**Killer property:** even a successful SQL injection cannot cross tenants.

The package **publishes a reviewable migration** for role provisioning (role +
`GRANT`s + `ALTER DEFAULT PRIVILEGES`) rather than running privileged role DDL
itself.

### Bypass mechanism is model-dependent

- **`owner`:** policies emit `USING ( rls.bypass() OR <predicate> )`.
  `rls.bypass()` reads a transaction-local GUC set only inside `system()`. Safe
  because the owner is already privileged.
- **`restricted`:** policies emit `USING ( <predicate> )` only — **no bypass
  clause**. Any role can `set_config('app.bypass', …)` on a custom GUC, so a GUC
  bypass would let the restricted role escape its own jail. Bypass is exclusively
  *route to the admin connection*. `Rls::system()` **hard-fails** if no admin
  connection is configured — no silent GUC fallback.

---

## 8. SQL helper functions (`rls.*`)

Dedicated `rls` schema. These are a public API surface (policies hard-depend on
them): **signatures are semver-stable** — add new functions, never mutate
existing signatures.

### Install: migrations by default, extension optional

- **Default: migrations** using `CREATE OR REPLACE FUNCTION` (idempotent), with
  installed version tracked in `rls.meta`. Portable to managed Postgres (RDS,
  Cloud SQL, Supabase, Neon).
- **Optional: extension** (`rls:install --extension`) for superuser/self-hosted
  environments — `pg_dump`-clean, `ALTER EXTENSION … UPDATE` versioning,
  extension-owned functions.
- **Both artifacts are single-sourced** from one canonical definition; install
  method is **invisible downstream** (policies and app code are identical either
  way).

### Definitions

```sql
rls.context(key text) returns text        -- current_setting('app.' || key, true)
rls.tenant_id() returns uuid               -- generated from ContextSchema, sugar
rls.bypass() returns boolean               -- owner-mode only
```

- **Volatility: `STABLE`** (critical). `STABLE` lets the planner evaluate the
  helper once per statement and drive an **index scan** on `tenant_id`. VOLATILE
  makes the policy predicate non-sargable → sequential scans on every query.
- **`PARALLEL SAFE` vs `RESTRICTED`:** to be confirmed empirically (custom GUCs
  *are* propagated to parallel workers; verify on partitioned tables).
- **Security context:** context readers are `SECURITY INVOKER` (plain GUC reads).
  `SECURITY DEFINER` is reserved for the advanced auth-lookup case (§9), locked
  down with `SET search_path = ''` and schema-qualified bodies.

---

## 9. Migration & policy DSL

Layered; generic core with *earned* sugar.

```php
// Layer 1: primitives (escape hatch)
$table->enableRowLevelSecurity();
$table->forceRowLevelSecurity();

// Layer 2: policy builder (generic, column → dimension)
$table->rlsPolicy('tenant_isolation')
    ->matches('tenant_id', dimension: 'tenant_id')  // tenant_id = rls.context('tenant_id')::type
    ->for('all')                                      // or ->for('select','insert',…)
    ->restrictive()                                   // default
    ->to('app_role')                                  // optional: TO role
    ->name('...');                                    // optional override

// Layer 3: earned sugar (generated from declared scope dimension)
$table->scopedBy('tenant_id');       // always available, generic
$table->scopedBy('tenant_id')->withDefault();  // opt-in column default
$table->tenantIsolated();            // only exists because you declared a tenant_id scope
```

### Non-obvious safety defaults (all overridable)

1. **Isolation policies are `RESTRICTIVE` by default.** Multiple PERMISSIVE
   policies combine with **OR** — so a permissive isolation policy + a permissive
   "published docs visible" policy would OR together and leak other tenants'
   published docs. RESTRICTIVE (AND) makes the isolation predicate constrain
   *every* query. `->permissive()` opts out.
2. **Both `USING` and `WITH CHECK`, always.** `USING` filters what you can see;
   `WITH CHECK` filters what you can write. Omitting `WITH CHECK` lets a tenant
   `INSERT` rows stamped with another tenant's id. Emitted together from one
   `matches()` call.
3. **`withDefault()` is opt-in.** `tenant_id DEFAULT rls.context('tenant_id')`
   auto-fills on insert; combined with `WITH CHECK` it's "cannot get it wrong."
   Kept opt-in because auto-populating columns surprises people, and `WITH CHECK`
   already provides the hard guarantee. (Caveat: the default only fires when the
   column is *omitted*; an explicit `null` bypasses it — the check still catches
   it.)

Plumbing: stable auto-names (`<table>_<dimension>_isolation`, overridable) for
idempotent `DROP POLICY IF EXISTS` and rollback; the macro registers `down()`;
output is role-model-aware (FORCE and the `rls.bypass()` clause only in owner
mode).

---

## 10. Authentication

Reframe: **auth is not a subsystem — it is "the operation that runs before
context exists,"** consuming the exempt-tables + bypass primitives. Two things
only:

1. **Exempt tables** — identity/global tables RLS never manages (`users`,
   `password_reset_tokens`, `sessions`, `tenants`, `jobs`, `migrations`). Queried
   during login before a tenant is known; the fail-loud guard knows they're
   exempt. In `restricted` mode the provisioning migration `GRANT`s the runtime
   role access to them.
2. **User-defined context resolver + auth-event bridge.** The app defines the
   identity → context mapping; the package establishes context on Laravel's
   `Authenticated` event.

```php
Rls::resolveContextUsing(
    fn (#[CurrentUser] ?Authenticatable $user) => $user
        ? ['user' => $user->getAuthIdentifier()]
        : []
);
```

- Invoked through the container (`Container::call`) so `#[CurrentUser]` and DI
  resolve.
- Nullable: guests → empty context → guard correctly fires on RLS tables.
- Shipped in the **publishable app-side `RlsServiceProvider` stub** (the single
  app-side config home for both `defineContext` and `resolveContextUsing`),
  upgrade-safe and fully customizable. The default maps only `user`; the team
  adds their tenancy dimension.

**Security-critical:** the resolver must derive context from *authenticated*
identity — a resolver returning a tenant id from an untrusted header/param is a
one-line total tenant takeover. Documented loudly.

Flows fall out naturally: login (users exempt → `Authenticated` → resolver),
subsequent requests (middleware → resolver), registration (create tenant, then
`Rls::actingAs($newTenant, fn () => …)`), subdomain-first (context set before
auth). `SECURITY DEFINER` auth-lookup functions are documented-advanced-only, not
default.

---

## 11. Jobs & queues

Mostly solved by Laravel `Context`: context values serialize into the job payload
at dispatch and rehydrate in the worker. Package additions:

1. **Propagate by default, opt out explicitly.** A job inherits the dispatcher's
   tenant scope (sees only that tenant's data) — correct-by-default. System jobs
   opt out via `Rls::system(...)` in `handle()` or a `#[WithoutRlsContext]` /
   `SystemJob` marker.
2. **Strip bypass at the dehydrate boundary.** A job dispatched inside a
   `system()`/bypass scope must **not** carry bypass into the worker. Context's
   `dehydrating` hook drops bypass/privileged dimensions; privilege must be
   re-declared in `handle()`.
3. **Per-job stack reset** (`JobProcessing`) as belt-and-suspenders against
   leakage on long-lived workers.
4. **Restricted-mode nuance:** a `system()` job needs the admin connection to
   bypass, or `system()` hard-fails (consistent with §7).

Documented edges: delayed/retried jobs replay the dispatch-time snapshot (may be
stale); batches/chains each carry their own snapshot.

---

## 12. Artisan commands

Console starts with empty context (fail-loud on RLS tables). Per-tenant work is
explicit (`Rls::actingAs(...)`); "run for all tenants" is an app-side loop (no
blessed tenant model).

**Migrations auto-bypass RLS by default** (opt-out). Under `owner` + FORCE, a
data migration with no context evaluates `tenant_id = rls.tenant_id()` against
NULL → **silently updates zero rows.** The package wraps the migration lifecycle
(`MigrationsStarted`) in a `system()` scope so backfills actually work.

Shipped commands:

- **`rls:install`** — publish config/provider/migrations; `--extension` for the
  extension path.
- **`rls:check`** — audit that every table matching a declared scope has RLS
  enabled + a policy (+ FORCE in owner mode); fail CI on drift. Cheapest
  insurance against the highest-severity mistake (new table, forgotten policy).
- **`rls:audit`** — statically scan for bypass call sites; report count +
  locations; fail CI over a threshold. First-class, equal to `rls:check`.
- **`rls:sync`** — regenerate typed SQL helpers from the declared `ContextSchema`
  (explicit, so policies never shift silently).
- **`rls:upgrade`** — migrate `rls.*` helper version.

---

## 13. Testing toolkit

Testing is a first-class, richly-tooled surface — RLS bugs are invisible until
they're a breach, so the toolkit must make proving isolation trivial and make a
silently-passing test hard. Shipped as an `InteractsWithRls` trait (+ Pest
helpers).

**Context control:** `withRlsContext($map[, $cb])`, `actingAsTenant($t)` (earned
alias), `switchRlsContext($a, $b, $cb)`, `rlsContext()`, `forgetRlsContext()`.

**Bypass / arrange:** `withoutRls([$cb])`, `asSystem($cb)`,
`createForTenant($tenant, $factory)` (creates under that tenant's context so
`WITH CHECK` passes and `tenant_id` auto-fills — avoids raw inserts that skip the
policies under test).

**Structural assertions:** `assertRlsEnabled`, `assertRlsForced`,
`assertHasPolicy`, `assertPolicyIsRestrictive`, `assertTableProtected` (one-shot:
enabled + forced-if-owner + restrictive policy).

**Behavioral assertions:** `assertRlsIsolates($model, from:, cannotSee:)`,
`assertVisibleTo($ctx, $model, $ids)`, `assertNotVisibleTo`,
`assertCannotWriteAcrossTenants(...)` (expects `WITH CHECK` rejection),
`assertMissingContextThrows($cb)`, `assertRlsScoped($cb)`.

**Introspection / debugging** (the "why is my result empty?" answer):
`dumpRlsContext()` (PHP stack + live DB GUCs side by side), `dumpRlsPolicies($t)`
(pretty-prints `pg_policies`), `Rls::explain($cb)` (which policies apply,
evaluated context values, effective predicate).

**Environment / declarative:** `useRlsStrategy('session')`,
`withRlsRoleModel('restricted')`, `#[RlsContext([...])]` per-test attribute,
`#[WithoutRls]` (class/method).

**Automatic leak canary:** the trait's `tearDown` asserts an empty context stack
and no leaked GUC — a test that forgets cleanup fails.

**Pest + parallel:** first-class Pest functions and `expect()` extensions; tested
against `RefreshDatabase`, `DatabaseMigrations`, `DatabaseTransactions`, and
`--parallel` (process-local context).

Canonical test:

```php
public function test_documents_are_tenant_isolated(): void
{
    [$a, $b] = Tenant::factory()->count(2)->create();

    $this->withoutRls('seed', fn () => Document::factory()->for($a)->count(2)->create());
    $this->createForTenant($b, Document::factory()->count(3));

    $this->assertTableProtected('documents');
    $this->assertRlsIsolates(Document::class, from: $a, cannotSee: $b);
    $this->assertCannotWriteAcrossTenants(Document::class, actingAs: $a, tenant: $b->id);
}
```

---

## 14. Performance

Round trips dominate; server CPU is noise (a read-only transaction writes no WAL
and its COMMIT forces no fsync; `set_config` is trivial).

- **Baseline cost:** a bare context-bearing query on an RLS table under `wrap`
  becomes `BEGIN → set_config → query → COMMIT` — 4 round trips vs 1. Invisible
  on a 0.3 ms LAN; +10–15 ms per un-batched query across a PgBouncer hop /
  cross-AZ.
- **Amortization is the real lever.** Work inside a `DB::transaction()` (or the
  request middleware) pays `set_config` **once** for N queries. This is the
  documented idiomatic path — and the closest thing Laravel has to a Unit-of-Work
  flush boundary (Eloquent executes eagerly/statelessly, so there is no natural
  batching point).
- **Pipelining would collapse the round trips** (libpq 14+), **but PDO exposes
  no pipeline API** — a known ceiling we don't design around.
- **BEGIN+set_config merge** (simple-query multi-statement) is possible but
  requires inlining the value → kept off the default path (violates bound-param
  rule); available as an advanced opt-in.
- **`session` strategy** erases per-query cost where topology permits.

The round-trip cost and the PgBouncer prepared-statement caveat (§15) are
documented front-and-center, not hidden.

---

## 15. PgBouncer / pooling compatibility

| Pooling mode | Supported | Notes |
|---|---|---|
| Direct / RDS Proxy / Hyperdrive | ✅ | `transaction` or `session` strategy |
| Session pooling | ✅ | `session` strategy safe |
| **Transaction pooling** | ✅ (primary target) | `transaction` strategy only; the reason the package exists |
| Statement pooling | ❌ | No per-connection/transaction state possible; refused |

**Prepared-statement caveat (must document):** PDO pgsql uses native server-side
prepares by default. Under transaction pooling **before PgBouncer 1.21**, native
prepares break. Fix: PgBouncer ≥ 1.21 with `max_prepared_statements`, **or**
`PDO::ATTR_EMULATE_PREPARES => true`. Orthogonal to RLS, but users will blame the
package, so we own the docs.

---

## 16. Bypass discipline & observability

Fail-closed design creates pressure toward bypass (every missing-context annoyance
nudges toward `withoutRls()`), and a codebase peppered with unaudited bypass is
*worse* than no RLS — same cost, plus holes, plus false confidence. Therefore:

- **Closure-only, reason-required:** `Rls::system('reason', fn () => …)`,
  `Rls::withoutRls('reason', fn () => …)`. No imperative bypass.
- **Every bypass fires `RlsBypassed` and logs** (configurable channel) with the
  reason — visible in review and prod logs with intent attached.
- **`rls:audit`** reports bypass call sites and count; fails CI over a threshold.
- Bypass is easy-when-scoped and visible-always.

---

## 17. Threat model & honest limitations

**Stops:**
- Forgotten `WHERE tenant_id` in app code (Eloquent, builder, raw) — fail-closed.
- Bugs in a global scope; IDOR-style load-by-id-without-tenant-check.
- **`restricted` mode only:** a successful SQL injection cannot cross tenants.

**Does not stop:**
- **`owner` mode:** a compromised credential (can `DISABLE RLS`/`DROP POLICY`);
  injection that runs DDL. Owner mode stops developers, not attackers.
- Data paths outside Postgres: Scout/search indexes, Redis cache, read replicas
  (unless context re-established there), direct BI connections.
- A resolver fed untrusted input (sets attacker's own tenant) — the crown-jewel
  risk.
- Leaked/unclosed bypass scopes (mitigated by closure-only + logging).

**New attack surface added:**
- `SECURITY DEFINER` functions (search_path / escalation) — minimized, locked
  down, advanced-only.
- The `rls.*` functions are a single point of failure for every policy —
  versioning discipline is a security control.

**Secure-default recommendations:** `restricted` mode for real isolation;
resolver from authenticated identity only; RESTRICTIVE isolation policies; `USING`
+ `WITH CHECK`; bypass logged/audited; `rls:check` in CI.

---

## 18. Failure modes (ranked by severity)

1. **Octane/worker context leak** (catastrophic cross-tenant read) — reset
   listener + production leak canary.
2. **Malformed context value = cluster-wide DoS** — validate at `set()` time.
3. **`session`-strategy context lost on reconnect** — re-establish on reconnect
   hook; `transaction` strategy is immune.
4. **Multi-connection / read-replica context gaps** — context set on one
   connection, query on another.
5. **`RefreshDatabase` false-pass** — if mid-txn re-inject regresses, the *entire
   suite* silently stops verifying isolation while staying green. Dedicated test.
6. Nested-transaction tenant change (throws), deadlock retry (context re-injects
   on `beginTransaction` re-fire), NULL/empty context, type-cast failures,
   `search_path` gaps in DEFINER functions, long-lived request-transaction locks.

---

## 19. Ecosystem positioning

- **stancl/tenancy, spatie/laravel-multitenancy** own Laravel multi-tenancy and
  *neither uses RLS as enforcement* (DB-per-tenant, connection switching, or
  app-level scopes). **Integrate beneath them, don't compete** — ship bridges
  (`resolveContextUsing(fn () => ['tenant_id' => tenant()?->getKey()])`) so we're
  the DB enforcement backstop under the tenancy package they already use. This is
  the core strategic opportunity.
- **Supabase / Hasura / PostGraphile** (inspirations) have it structurally
  *easier* — they *are* the query layer with full per-request lifecycle control.
  We retrofit the same idea onto a framework that executes queries eagerly and
  statelessly, which is why the transaction-boundary problem is hard for us. We
  borrow their ergonomics (`rls.tenant_id()` ≈ `auth.uid()`), not their
  architecture.
- Existing Laravel-RLS packages are thin (middleware `set_config`, no DSL, no
  test story, no pooling awareness). **The gap we fill is the complete,
  safe-by-default, testable, pooling-aware treatment.**

---

## 20. Public API (illustrative)

```php
// Context
Rls::actingAs(['tenant_id' => $t->id], fn () => …);   // scoped (recommended)
Rls::actingAs(['tenant_id' => $t->id]);                // imperative
Rls::set('tenant_id', $t->id);
Rls::get('tenant_id');
Rls::context();                                        // full map
Rls::current();                                        // ?RlsContext (stack top)
Rls::hasContext(): bool;

// Bypass (reason required, closure-only)
Rls::system('reason', fn () => …);
Rls::withoutRls('reason', fn () => …);

// Configuration (app-side RlsServiceProvider)
Rls::defineContext(fn (ContextSchema $c) => …);
Rls::resolveContextUsing(fn (#[CurrentUser] ?Authenticatable $u) => …);

// Migrations
$table->enableRowLevelSecurity();
$table->forceRowLevelSecurity();
$table->scopedBy('tenant_id')->withDefault();
$table->rlsPolicy('name')->matches('col', dimension: 'tenant_id')->for('all')->restrictive();
$table->tenantIsolated();                              // earned sugar

// Testing (InteractsWithRls)
$this->withRlsContext([...]); $this->withoutRls('reason', fn () => …);
$this->assertRlsIsolates(...); $this->assertCannotWriteAcrossTenants(...);
```

```sql
-- generated / helper SQL
rls.context('tenant_id')     rls.tenant_id()     rls.bypass()
```

---

## 21. Open questions for the PoC

1. **Boundary default (`wrap` vs `explicit`).** Default is `wrap` (bounded +
   observable), but there is a genuine argument for `explicit`. Validate the DX
   of both on a real app before locking.
2. **Fail-loud granularity for raw SQL.** Per-table detection is clean for the
   query builder, fuzzy for raw SQL. Decide the raw-SQL fallback after the PoC.
3. **`PARALLEL SAFE` vs `RESTRICTED`** for `rls.*` — confirm empirically on
   partitioned/parallel scans.
4. **Blueprint macro inheritance** with tpetry's schema subclass — verify.
5. **Read-replica context propagation** — confirm the injection path on replica
   connections.

---

## 22. Decision log

| # | Decision | Rationale |
|---|---|---|
| 1 | Transaction = default context unit; `set_config` bound-param only | Only safe mechanism under transaction pooling; fail-safe; injection-proof |
| 2 | Strategies: `transaction` (default) / `session` (opt-in); statement pooling unsupported | Correct-everywhere default; performance escape hatch |
| 3 | Boundary policy `wrap` (bounded+observable) / `explicit` / `request` | Reconciles safety with the "no invisible magic" critique |
| 4 | Fail-loud in app + fail-closed in DB | Defense in depth in both directions |
| 5 | Role model `owner` (FORCE, default) / `restricted` (admin conn, recommended) | Zero-infra adoption vs real isolation; honestly labeled |
| 6 | Bypass: GUC clause in owner mode, admin-connection in restricted; `system()` hard-fails without admin conn in restricted | Restricted role must not escape its own jail |
| 7 | Publish provisioning migration, don't run privileged DDL | Reviewable, no surprise infra changes |
| 8 | Generic named-dimension context; opt-in declared-schema sugar | Blesses no auth model |
| 9 | Build on Laravel `Context` (11+) | Free job propagation + reset |
| 10 | `rls` schema; migrations default, extension opt-in; single-sourced | Managed-Postgres portability + superuser upgrade path |
| 11 | `STABLE` volatility on helpers | Index-scan-able policies; avoids per-row tax |
| 12 | RESTRICTIVE isolation policies + `USING`+`WITH CHECK` by default | Prevent OR-leak and cross-tenant writes |
| 13 | Auto-bypass migrations (opt-out) | Prevent silent zero-row backfills under FORCE |
| 14 | Single package, modular internals, conditional registration | Atomic versioning/audit; composability via config |
| 15 | Bypass reason-required + logged + `rls:audit` | Counter the bypass-erosion incentive |
| 16 | Integrate beneath tenancy packages; interop with tpetry | Strategic positioning; not a competitor |
| 17 | Testing is a first-class tooled surface + leak canary | RLS bugs are invisible until they breach |
