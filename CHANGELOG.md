# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project aims
to follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html) once it
reaches `1.0.0`. While on `0.x`, minor versions may contain breaking changes.

## [Unreleased]

### Added

- **`$table->tenantIsolated()` schema macro** — earned sugar for
  `isolatedBy('tenant_id')` that reads the column type from the declared context
  schema. Fails loudly if no `tenant_id` dimension has been declared.
- **Testing assertions `assertVisibleTo()` / `assertNotVisibleTo()`** on the
  `InteractsWithRls` trait: assert that a set of model keys is (or is not) visible
  under a given isolation context. Subset semantics, so extra visible rows do not
  fail the assertion.
- **Opt-in nested-transaction tenant-change guard.** Set `rls.on_nested_change`
  to `'throw'` to raise `NestedTenantContext` when an isolation key changes to a
  different value while a transaction is already open — catching a transaction
  that would otherwise silently straddle two tenants. Default `'allow'` preserves
  current behavior (and keeps the `RefreshDatabase` test harness, which wraps each
  test in a transaction, working unchanged).

### Changed

- **`rls.context()` and the generated typed helpers (`rls.<dimension>()`) are now
  declared `PARALLEL SAFE`.** PostgreSQL derives a query's parallel-safety from
  the RLS policy's declared function, so the `CREATE FUNCTION` default (`PARALLEL
  UNSAFE`) was silently forcing a serial plan on every isolated table. Isolated
  tables can now use parallel query plans; results stay correctly scoped because
  parallel workers inherit the `app.*` GUCs. Re-run the functions migration and
  `rls:sync` to pick up the change.

### Fixed

- **Context now reaches jobs on long-lived (daemon) queue workers and Octane, not
  only under `queue:work --once`.** `RlsManager` (a singleton) captured the
  Context repository at construction, but that repository is a *scoped* binding
  the worker/Octane reset between jobs/requests — so the manager read a stale,
  unhydrated repository and every scoped query fell back to zero rows. The manager
  now resolves the live repository from the container on each access. The failure
  was fail-closed (no cross-tenant leak), but it broke scoped work on the most
  common deployment.
- Compound isolation keys now work: a table with two or more `isolatedBy()` calls
  no longer fails to migrate on a duplicate `"<table>_access"` permissive policy.
  The shared base policy is created idempotently (one per table). This is the
  compound-key usage the README documents; it was previously unreachable.

## [0.0.1]

First public, pre-release cut. The mechanism works end to end against real
PostgreSQL and is covered by 122 tests, including transaction pooling
(PgBouncer), a live `queue:work` cycle, read replicas, and two distinct
database roles.

### Added

- `isolatedBy()` schema macro: enables RLS on a table with a permissive base
  policy and a restrictive isolation policy (`column = rls.context(...)`), with
  optional `withDefault()`.
- `Rls` facade: `defineContext()`, `resolveContextUsing()`, `isolateTo()`,
  `withoutIsolation()` / `system()`.
- Two role models — `owner` (FORCE RLS, contains mistakes) and `restricted`
  (separate role, contains a compromised credential).
- Strategies (`transaction` / `session`) and boundaries (`wrap` / `explicit` /
  `request`), with fail-closed defaults.
- Admin-connection-only bypass (no in-band SQL escape hatch), with an event, a
  log line, and `rls:audit`.
- Context propagation into queued jobs; leak canary for long-lived workers.
- Commands: `rls:install`, `rls:check`, `rls:audit`, `rls:sync`.
- `InteractsWithRls` testing trait with isolation assertions.
- Performance harness (`composer bench`) with a checked-in baseline, endpoint
  and latency-sweep cells, and a documented overhead table.

### Not yet

- No independent security audit; the adversarial security suite (Milestone B)
  and the full version-matrix CI (Milestone C) are still open. Not for
  production use — see the README status banner.

[Unreleased]: https://github.com/Radiergummi/laravel-rls/compare/v0.0.1...HEAD
[0.0.1]: https://github.com/Radiergummi/laravel-rls/releases/tag/v0.0.1
