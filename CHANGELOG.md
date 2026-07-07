# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project aims
to follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html) once it
reaches `1.0.0`. While on `0.x`, minor versions may contain breaking changes.

## [Unreleased]

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
