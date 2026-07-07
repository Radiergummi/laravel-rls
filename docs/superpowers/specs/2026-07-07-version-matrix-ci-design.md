# Milestone C — Version-matrix CI — Design

**Date:** 2026-07-07
**Status:** approved, pre-implementation
**Milestone:** C (see [`docs/MILESTONES.md`](../../MILESTONES.md) § "Milestone C")

## Goal

Every push and PR proves the library green across the *supported* matrix — not just
the one PG/PHP/Laravel combination on the author's laptop — and the PgBouncer-gated
tests that currently **skip** in CI actually run. This is the substrate that keeps
Milestones A and B honest over time.

Concretely, close the three gaps the current minimal CI leaves open:

1. **PostgreSQL version** — only PG 18 has ever run. This is the headline gap
   Milestone C exists to close, and the highest-signal axis for an RLS library
   (planner/policy behavior, `FORCE`, `security_invoker` views evolved across 14→18).
2. **Laravel line** — only one line has run; the package supports 11/12/13.
3. **PgBouncer pooling** — `PgBouncerTest` and `CrossWorkerLeakageTest`'s row-level
   case skip in CI because no pooler is stood up.

## Decisions (locked with the user)

| Decision | Choice | Rationale |
|---|---|---|
| Matrix shape | **Two orthogonal planes** | A full PG×PHP×Laravel×deps cross is ~100 cells and mostly redundant — PG version rarely interacts with language/framework version. Two planes cover every dimension for ~20 cells. |
| PHP 8.5 + Laravel 13 | **Include both now** | All dev deps already support L13; author already develops on 8.5 with the suite green. Including them closes the "only one combination ever ran" gap this milestone targets. |
| PgBouncer | **Separate dedicated job** | Pooling behavior is PG- and PHP-version-agnostic, so one cell gives all the signal. GitHub Actions also can't conditionally add a service to a single matrix cell. |
| Perf-regression job | **Dropped / deferred** | `BenchSmokeTest` already runs `bench/run.php` end-to-end inside the normal test job, so harness integrity is covered. Percentile gating on shared GitHub runners is too noisy to be a reliable gate. `bench/baseline.json` stays a local regression reference. |

## Version facts (from Packagist, authoritative — verified 2026-07-07)

| Package | Relevant constraint |
|---|---|
| `laravel/framework` 13 | requires **PHP `^8.3`** → L13 row floors at 8.3, no 8.2 |
| `laravel/framework` 11, 12 | PHP `^8.2` |
| `orchestra/testbench` | 9 ↔ L11, 10 ↔ L12, 11 ↔ L13; testbench 11 requires PHP `^8.3`, phpunit `^11.5.50\|^12\|^13` |
| `tpetry/laravel-postgresql-enhanced` `^3.7` | allows `^13.0` ✓ |
| `laravel/octane` `^2.17` | allows `^13.0` ✓ |

The root `phpunit/phpunit: ^11.0` intersects testbench 11's `^11.5.50|…` to
`≥11.5.50 <12` — resolvable, no phpunit bump needed.

## Changes

### 1. `composer.json`

- `illuminate/support` · `illuminate/database` · `illuminate/contracts`:
  `^11.0|^12.0` → `^11.0|^12.0|^13.0`
- `orchestra/testbench`: `^9.0|^10.0` → `^9.0|^10.0|^11.0`
- `phpunit/phpunit`: **unchanged** (`^11.0`).

### 2. `.github/workflows/ci.yml`

Five jobs, all `fail-fast: false`.

#### Job `test` — compat plane (Postgres 18)

The Laravel line is forced per cell by overriding the testbench constraint before
`composer update`:

```
composer require --dev "orchestra/testbench:${{ matrix.testbench }}" --no-interaction --no-update
composer update --${{ matrix.deps-flag }} --prefer-dist --no-interaction --no-progress
```

where `deps-flag` is `prefer-stable` or `prefer-lowest --prefer-stable`.

Built from an explicit `include:` list (the shape is irregular — L13 skips 8.2, and
prefer-lowest runs only at each line's floor PHP), **not** a cross with `exclude`:

| Laravel | testbench | PHP | deps |
|---|---|---|---|
| 11 | `^9`  | 8.2, 8.3, 8.4, 8.5 | prefer-stable |
| 12 | `^10` | 8.2, 8.3, 8.4, 8.5 | prefer-stable |
| 13 | `^11` | 8.3, 8.4, 8.5      | prefer-stable |
| 11 | `^9`  | 8.2 | prefer-lowest |
| 12 | `^10` | 8.2 | prefer-lowest |
| 13 | `^11` | 8.3 | prefer-lowest |

= 11 prefer-stable + 3 prefer-lowest-at-floor = **14 cells**. prefer-lowest runs
only at each line's floor PHP because that is where under-constrained deps bite;
running it on 8.5 would only surface floor-dep-on-new-PHP noise.

Service: `postgres:18`, ports `5432:5432`, `pg_isready` health check. Steps:
checkout → setup-php (`pdo`, `pdo_pgsql`) → require testbench line → `composer update`
→ `./tests/bin/setup-db.sh` → `composer test`.

#### Job `test-postgres` — PG plane

`matrix.pg: [14, 15, 16, 17]`, service `image: postgres:${{ matrix.pg }}`, fixed
**PHP 8.4 + Laravel 12 (testbench `^10`) + prefer-stable**. PG 18 is already covered
by every compat cell, so this adds only the 4 missing versions = **4 cells**. Same
steps as `test`. `setup-db.sh` is PG-version-agnostic (works 14–18; its
`GRANT CREATE ON SCHEMA public` is a PG15+ need, harmless on 14).

#### Job `pgbouncer` — pooling

Two service containers on the job's network:

- `postgres` — `postgres:18`, `5432:5432`, health check.
- `pgbouncer` — `edoburu/pgbouncer:latest`, `6432:5432`, env mirroring the
  known-good local `tests/bin/setup-pgbouncer.sh`: `DB_HOST=postgres` (the service
  label — both are service containers on the same network, reachable by name on the
  internal port), `DB_PORT=5432`, `DB_USER=rls_app`, `DB_PASSWORD=secret`,
  `DB_NAME=rls_test`, `POOL_MODE=transaction`, `AUTH_TYPE=scram-sha-256`,
  `MAX_CLIENT_CONN=100`, `DEFAULT_POOL_SIZE=20`.

PHP 8.4, prefer-stable, `postgres:18`. Runs the full `composer test` → **unskips**
`PgBouncerTest` (3 tests, gated on `127.0.0.1:6432`) and `CrossWorkerLeakageTest`'s
PgBouncer row-level case (1). Boot order is safe: pgbouncer connects to Postgres
lazily (first client connection at test time), and `setup-db.sh` creates `rls_app`
before `composer test` runs.

#### Jobs `static-analysis` and `format`

Unchanged in shape (single PHP 8.4, `composer update` at highest → resolves to L13).
The static-analysis job is also where we confirm larastan/phpstan cope with
illuminate 13.

**Total ≈ 14 + 4 + 1 (pgbouncer) + 1 (phpstan) + 1 (pint) = 21 jobs.**

### 3. Setup scripts

No changes expected. `setup-db.sh` runs as the container superuser over the mapped
`5432` and is version-agnostic. The local re-run gotcha (evict the pgbouncer session
before `DROP DATABASE`) is a local-only concern — CI databases are always fresh.

### 4. README badge + branch protection

- Ensure the README CI badge points at the workflow status (`…/actions/workflows/ci.yml/badge.svg`).
- Make green **required to merge** on `main` via a branch-protection rule
  (`gh api -X PUT repos/Radiergummi/laravel-rls/branches/main/protection …`). Needs
  repo-admin; run with the user's go-ahead at that step, or hand them the command.

## Verification strategy (CI green at each step)

1. Bump `composer.json`; locally `composer update` + run the suite (confirms
   highest-version = L13 resolution is green); commit.
2. Locally dry-run each Laravel line's resolution before pushing:
   `composer require --dev orchestra/testbench:^9|^10|^11` × `--prefer-lowest`
   against the local PG, to catch resolution breaks without burning CI cycles.
3. Rewrite `ci.yml`; push; watch `gh run` until green, iterating on the PG-plane and
   pgbouncer specifics against real CI.
4. Docs (`MILESTONES.md` status + badge), branch protection, memory update.

Each change is its own commit, kept CI-green.

## Risks to verify during implementation

- **larastan/phpstan on illuminate 13** — the static-analysis job resolves to L13;
  confirm it analyses clean, narrow/pin if a floor breaks.
- **pgbouncer scram service-to-service auth in CI** — works locally with host
  networking; swapping to the service-network hostname (`DB_HOST=postgres`) is the
  untested part. Validate against real CI; fall back to `AUTH_TYPE=md5` if scram
  misbehaves.
- **prefer-lowest floor resolution per line** — pre-validated locally in step 2.

## Done when

- `.github/workflows/ci.yml` runs the two-plane matrix (compat @ PG18 + PG 14–17
  plane) plus static-analysis and format, green on push and PR.
- The `pgbouncer` job runs `PgBouncerTest` and the cross-worker row-level test
  **not skipped**.
- README carries a CI status badge; `main` requires green to merge.
- `docs/MILESTONES.md` § C marked done (with the perf-regression job noted as a
  deliberate deferral, not a silent drop) and the memory updated.
