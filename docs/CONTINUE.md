# Continuation guide

Everything needed to pick this project back up. Read this first.

## What this is

`radiergummi/laravel-rls` — a Laravel package making PostgreSQL Row-Level
Security a transparent tenant-isolation layer. The design was brainstormed in
full, an implementation plan written, and a **proof of concept built covering
the entire design surface** (122 tests, all green against real Postgres 18),
plus the Milestone A performance harness.

Goal of the PoC was to answer *"does this actually work across the hard cases —
connection pooling, jobs, restricted roles, other connection packages?"* — and
the answer is **yes**, with specific gotchas now captured in code and tests.

## The map

| Document | What it is |
|----------|-----------|
| [`README.md`](../README.md) | Package intro, usage, config, key findings, how to run |
| [`docs/superpowers/specs/2026-07-04-laravel-postgresql-rls-design.md`](superpowers/specs/2026-07-04-laravel-postgresql-rls-design.md) | **Full design & threat model** (22 sections + decision log). The source of truth for intent. |
| [`docs/superpowers/plans/2026-07-04-laravel-rls-poc.md`](superpowers/plans/2026-07-04-laravel-rls-poc.md) | The 8-task TDD plan the core PoC was built from |
| [`docs/BACKLOG.md`](BACKLOG.md) | Prioritized remaining work (P0 hardening → P2 polish) + open decisions |
| This file | Handoff / how to resume |

## Current state

- **Branch:** `main` (one self-contained commit per feature). `v0.0.1` tagged and
  on Packagist. A few commits are unpushed — direct push to `main` is blocked by
  the auto-mode classifier, so push manually (`git push origin main`).
- **Tests:** `vendor/bin/phpunit` → **183 tests, 1 skipped** (documented timing
  side channel), all passing. PHPStan + Pint clean.
- **Milestones:** **A (performance harness) done**, **B (adversarial security
  suite) done** (all 8 threat categories; found + fixed two real bugs — the
  compound-key macro and daemon/Octane context loss), **C (version-matrix CI)
  partial** — minimal CI is green, the full matrix is still open.
- **P0 hardening complete**, **most of P1 done.** P0: leak canary, context value
  validation, resolver-collision guard, session reset/reconnect, read-replica
  context, real-PgBouncer test. P1: `withDefault()`, bypass hardening +
  observability (event/log/`rls:audit --threshold`), `rls:install`, `rls:sync`,
  tenancy docs recipe.
- **P2 polish done (2026-07-07):** `PARALLEL SAFE` on `rls.context()` + the
  generated typed helpers; opt-in nested-transaction tenant-change guard
  (`rls.on_nested_change`); `assertVisibleTo()`/`assertNotVisibleTo()` testing
  assertions; `tenantIsolated()` earned-sugar macro.
- **CI:** `.github/workflows/ci.yml` runs tests (PHP 8.2–8.4 × Postgres 18),
  PHPStan, and Pint. The full version matrix + PgBouncer job is Milestone C.
- **Remaining (see [`docs/BACKLOG.md`](BACKLOG.md) → "Remaining open items"),** all
  decision-gated or larger. Decision-gated: `rls:upgrade` (versioning scheme),
  per-table fail-loud for raw SQL (open policy question), migration auto-bypass
  (now needs an `admin_connection` — design call). Additive: `--extension`/PGXN
  path, first-class tenancy bridge, the richer test-tooling tail, package
  extraction.

## Bring the environment back up

Requires PHP 8.2+ (built on 8.5), Composer, Docker.

```bash
cd /Users/moritz/Projects/laravel-rls
composer install

docker run -d --name rls-pg -e POSTGRES_PASSWORD=postgres -p 5432:5432 postgres:18
# wait for it, then create the roles + db:
./tests/bin/setup-db.sh          # creates rls_app (owner), rls_restricted (non-owner), rls_test

vendor/bin/phpunit               # should be all green
docker rm -f rls-pg              # when done
```

`PgBouncerTest` is skipped unless a bouncer is reachable on `127.0.0.1:6432`.
To run it: `./tests/bin/setup-pgbouncer.sh` (then `docker rm -f rls-pgbouncer`).

**Critical gotcha:** tests connect as the non-superuser `rls_app`, not `postgres`.
Superusers (and BYPASSRLS roles) skip RLS entirely — testing as one makes every
isolation test falsely pass.

## Code layout (`src/`)

```
RlsServiceProvider.php        binds manager, registers connection resolver (in boot,
                              to win the resolver race), Authenticated listener,
                              bypass handler (admin-connection swap, both role models), commands
Context/
  RlsContext.php              immutable value object (a pure isolation-key/value bag)
  RlsManager.php              stack (stored in Laravel Context), isolateTo/withoutIsolation/system,
                              in-flight isBypassing() flag, resolver, defineContext + typed __call accessors
  ContextSchema.php           declared isolation keys -> typed SQL helper generation
Database/
  HandlesRlsContext.php       THE core trait: beginTransaction injection, run() wrap,
                              applyRlsContext (transaction vs session), fail-loud/explicit guard
  RlsPostgresConnection.php   PostgresConnection + trait
Schema/RlsSchemaMacros.php    isolatedBy / enableRowLevelSecurity / forceRowLevelSecurity
                              (+ rlsRaw grammar macro)
Support/RlsFunctions.php      rls.context() SQL helper (single source)
Http/RlsRequestTransaction.php   opt-in per-request transaction middleware
Console/CheckCommand.php      rls:check (CI coverage audit)
Console/AuditCommand.php      rls:audit (bypass call-site scan)
Testing/InteractsWithRls.php  test helpers, assertions, leak canary
Exceptions/                   MissingIsolationContext, MissingContextBoundary, AdminConnectionRequired
Facades/Rls.php
```

The **single most important file** is `Database/HandlesRlsContext.php` — it's where
context reaches Postgres. If you change one thing, understand that first.

## Tests as the spec

Each feature has a focused test; reading them is the fastest way to understand
behavior:

- `TenantIsolationTest` — the headline owner-mode proof (reads/writes/fail-closed/restrictive)
- `OwnerModeBypassTest` — owner + FORCE + equality-only predicate; `system()` routes to the BYPASSRLS admin connection
- `RestrictedIsolationTest` — two real roles, non-owner confined with FORCE off, `system()` routing
- `ContextBackingTest` — Laravel Context dehydrate/hydrate roundtrip
- `QueuedJobContextTest` — live `queue:work` propagation
- `TpetryPostgresqlEnhancedInteropTest` — composability with another connection package
- `ContextInjectionTest`, `FailLoudGuardTest`, `ExplicitBoundaryTest`,
  `SessionStrategyTest`, `TypedHelpersTest`, `RequestTransactionMiddlewareTest`,
  `AuthContextTest`, `PolicyDslTest`, `Rls{Check,Audit}CommandTest`,
  `TestingHelpersTest`, `RlsFunctionsTest`, unit `RlsContext/RlsManager`.

## Where to start next

Open [`docs/BACKLOG.md`](BACKLOG.md) → **"Remaining open items"**. P0 hardening,
Milestones A and B, and the P2 polish batch are all done, so the highest-leverage
work left is:

1. **Milestone C — full version-matrix CI** (see [`MILESTONES.md`](MILESTONES.md)):
   multi-Postgres (14–18), Laravel 11/12/13 + `prefer-lowest`, a PgBouncer service
   that unskips the gated tests, and the optional perf-regression job. Bump
   `composer.json` to allow Laravel 13 + testbench 11 as part of this.
2. **Make the three decision-gated calls** before implementing them: `rls:upgrade`
   versioning scheme, the raw-SQL fail-loud policy, and how migration auto-bypass
   should behave now that bypass needs an `admin_connection`.

The additive items (`--extension` path, tenancy bridge, richer test tooling) need
no decision and can be picked up any time.

## Non-obvious things that will bite you

(All learned by building — see README "Key findings" for the full list.)

1. Test as a non-superuser or isolation tests lie.
2. A RESTRICTIVE-only table is invisible; you need a permissive base policy too.
3. Transaction-local GUCs can't be unset — blank to `''`, read via `nullif(...,'')`.
4. Composing on another connection package needs `run(): mixed`, capability
   detection (not `instanceof`), and resolver registration in `boot()`.
5. The fail-loud guard must skip DDL (or `migrate:fresh` trips it); PDO connects
   lazily so introspection uses `select()` + a re-entry flag.
