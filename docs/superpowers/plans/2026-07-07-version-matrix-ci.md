# Milestone C — Version-matrix CI — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `.github/workflows/ci.yml` prove the library green across the supported PostgreSQL × PHP × Laravel matrix on every push/PR, and run the PgBouncer-gated tests in CI instead of skipping them.

**Architecture:** Two orthogonal CI planes plus a pooling job. A **compat plane** (`test`) forces each Laravel line via a per-cell testbench constraint and runs PHP 8.2–8.5 × Laravel 11/12/13 (+ prefer-lowest at each line's floor) against Postgres 18. A **PG plane** (`test-postgres`) runs Postgres 14–17 at one representative PHP/Laravel combo. A dedicated **`pgbouncer`** job stands up a PgBouncer transaction-pooling service so `PgBouncerTest` and `CrossWorkerLeakageTest`'s row-level case run un-skipped. Static analysis and format jobs are unchanged in shape.

**Tech Stack:** GitHub Actions, `shivammathur/setup-php`, Composer, PHPUnit, PostgreSQL service containers, `edoburu/pgbouncer`.

## Global Constraints

- `illuminate/support` · `illuminate/database` · `illuminate/contracts` constraint: `^11.0|^12.0|^13.0` (exact).
- `orchestra/testbench` constraint: `^9.0|^10.0|^11.0` (exact).
- `phpunit/phpunit` stays `^11.0` — do NOT bump.
- Laravel↔testbench↔PHP-floor map: L11 ↔ testbench `^9.0` ↔ PHP 8.2; L12 ↔ `^10.0` ↔ PHP 8.2; L13 ↔ `^11.0` ↔ **PHP 8.3** (L13 has no 8.2 cell).
- Tests must connect as a **non-superuser** (`rls_app`) — never as `postgres` — or isolation falsely passes. `setup-db.sh` creates the roles as the container superuser over the mapped `5432`.
- PgBouncer transaction pooling requires `PDO::ATTR_EMULATE_PREPARES` — already set in `PgBouncerTest::defineEnvironment`. Do not touch the test.
- `composer.lock` is gitignored — bumping `composer.json` commits only `composer.json`.
- All matrix jobs use `fail-fast: false`.
- Every task ends CI-green; each change is its own commit.
- Repo: `github.com/Radiergummi/laravel-rls`, default branch `main`. Local PHP is 8.5.7.

---

## File Structure

- `composer.json` — widen `illuminate/*` and `orchestra/testbench` constraints (Task 1).
- `.github/workflows/ci.yml` — full rewrite from the current minimal CI to the five-job workflow (Tasks 2–3).
- `docs/MILESTONES.md` — mark § C done; note perf job deferred (Task 4).
- `README.md` — CI badge already present and correct (verify only, Task 4).
- Memory `poc-state.md` + `MEMORY.md` — update after C lands (Task 4).

No `src/`, `tests/`, or `tests/bin/*.sh` changes are expected — the setup scripts and gated tests already do the right thing; CI only needs to provide the infra they detect.

---

## Task 1: Widen dependency constraints for Laravel 11/12/13

**Files:**
- Modify: `composer.json:6-13` (the `illuminate/*` requires and `orchestra/testbench` require-dev)

**Interfaces:**
- Produces: a `composer.json` that resolves against Laravel 11, 12, and 13. Later CI tasks rely on `orchestra/testbench` allowing `^9.0|^10.0|^11.0` so a per-cell `composer require --dev orchestra/testbench:<constraint>` can pin each line.

- [ ] **Step 1: Edit `composer.json` require block**

Change the `require` section so the three illuminate constraints read:

```json
    "require": {
        "php": "^8.2",
        "illuminate/support": "^11.0|^12.0|^13.0",
        "illuminate/database": "^11.0|^12.0|^13.0",
        "illuminate/contracts": "^11.0|^12.0|^13.0"
    },
```

- [ ] **Step 2: Edit `composer.json` require-dev testbench constraint**

Change the `orchestra/testbench` line in `require-dev` to:

```json
        "orchestra/testbench": "^9.0|^10.0|^11.0",
```

Leave `phpunit/phpunit` at `^11.0` and every other dev dep unchanged.

- [ ] **Step 3: Resolve at highest (Laravel 13) and confirm the suite + static analysis are green**

Local PHP is 8.5, which satisfies L13's `^8.3` floor.

Run:
```bash
composer update --prefer-stable --no-interaction && composer show laravel/framework orchestra/testbench | grep -E "^versions"
```
Expected: `laravel/framework` resolves to a `13.x` line and `orchestra/testbench` to `11.x`.

Then run the full suite and static analysis (this is where we confirm larastan/phpstan cope with illuminate 13):
```bash
docker start rls-pg 2>/dev/null; ./tests/bin/setup-db.sh && composer test && composer lint && vendor/bin/pint --test
```
Expected: PHPUnit green (PgBouncer/cross-worker cases may SKIP if `:6432` is down locally — that is fine here), PHPStan `[OK] No errors`, Pint `PASS`.

- [ ] **Step 4: Dry-run the two lower Laravel lines and prefer-lowest floors**

This catches resolution breaks before they cost a CI cycle. For each line, pin testbench, update, and smoke the suite:

```bash
# Laravel 12
composer require --dev "orchestra/testbench:^10.0" --no-interaction --no-update
composer update --prefer-stable --no-interaction
./tests/bin/setup-db.sh && composer test

# Laravel 11
composer require --dev "orchestra/testbench:^9.0" --no-interaction --no-update
composer update --prefer-stable --no-interaction
./tests/bin/setup-db.sh && composer test

# prefer-lowest at the L11 floor (under-constrained-dep check)
composer update --prefer-lowest --no-interaction
./tests/bin/setup-db.sh && composer test
```
Expected: each `composer update` resolves without conflict and each `composer test` is green (skips allowed).

- [ ] **Step 5: Restore composer.json to the committed constraint and clean up**

The `composer require --dev` calls in Step 4 rewrote the testbench line in `composer.json`. Restore it so only the intended change is staged:

```bash
git checkout composer.json
```
Then re-apply Steps 1–2 edits (they were reverted by the checkout). Verify the diff is exactly the two constraint widenings:
```bash
git diff composer.json
```
Expected: only the `illuminate/*` and `orchestra/testbench` constraint lines changed.

- [ ] **Step 6: Commit**

```bash
git add composer.json
git commit -m "build(deps): allow Laravel 13 / testbench 11

Widen illuminate/* to ^11|^12|^13 and orchestra/testbench to ^9|^10|^11
so the CI matrix can pin each Laravel line. All dev deps (octane, tpetry,
larastan, pint) already span L11-L13; phpunit ^11.0 resolves on all lines.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 7: Push and confirm the existing (old) CI is still green**

```bash
git push
gh run watch --exit-status $(gh run list --branch main --limit 1 --json databaseId --jq '.[0].databaseId')
```
Expected: the current minimal workflow passes on the bumped `composer.json` (it resolves at highest = L13). If it fails on L13-specific issues, fix before proceeding.

---

## Task 2: Rewrite `ci.yml` — compat plane + PG plane (no PgBouncer yet)

Split from the PgBouncer job so a reviewer can gate the matrix independently of the riskier service-networking change.

**Files:**
- Modify: `.github/workflows/ci.yml` (full rewrite of the `test` job; add `test-postgres`; keep `static-analysis` and `format`)

**Interfaces:**
- Consumes: the widened `orchestra/testbench` constraint from Task 1.
- Produces: green `test` (14 cells) and `test-postgres` (4 cells) jobs. Task 3 appends a `pgbouncer` job to this same file.

- [ ] **Step 1: Replace `.github/workflows/ci.yml` with the compat + PG planes**

Write the file exactly as below:

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  test:
    name: "L${{ matrix.laravel }} · PHP ${{ matrix.php }} · ${{ matrix.deps }}"
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        include:
          # Laravel 11 (testbench ^9) — prefer-stable across the PHP range
          - { laravel: '11', testbench: '^9.0',  php: '8.2', deps: prefer-stable }
          - { laravel: '11', testbench: '^9.0',  php: '8.3', deps: prefer-stable }
          - { laravel: '11', testbench: '^9.0',  php: '8.4', deps: prefer-stable }
          - { laravel: '11', testbench: '^9.0',  php: '8.5', deps: prefer-stable }
          # Laravel 12 (testbench ^10)
          - { laravel: '12', testbench: '^10.0', php: '8.2', deps: prefer-stable }
          - { laravel: '12', testbench: '^10.0', php: '8.3', deps: prefer-stable }
          - { laravel: '12', testbench: '^10.0', php: '8.4', deps: prefer-stable }
          - { laravel: '12', testbench: '^10.0', php: '8.5', deps: prefer-stable }
          # Laravel 13 (testbench ^11) — PHP floor 8.3, no 8.2 cell
          - { laravel: '13', testbench: '^11.0', php: '8.3', deps: prefer-stable }
          - { laravel: '13', testbench: '^11.0', php: '8.4', deps: prefer-stable }
          - { laravel: '13', testbench: '^11.0', php: '8.5', deps: prefer-stable }
          # prefer-lowest at each line's floor PHP — catches under-constrained deps
          - { laravel: '11', testbench: '^9.0',  php: '8.2', deps: prefer-lowest }
          - { laravel: '12', testbench: '^10.0', php: '8.2', deps: prefer-lowest }
          - { laravel: '13', testbench: '^11.0', php: '8.3', deps: prefer-lowest }

    services:
      postgres:
        image: postgres:18
        env:
          POSTGRES_PASSWORD: postgres
        ports:
          - 5432:5432
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: pdo, pdo_pgsql
          coverage: none

      - name: Constrain Laravel line
        run: composer require --dev "orchestra/testbench:${{ matrix.testbench }}" --no-interaction --no-update

      - name: Install dependencies (${{ matrix.deps }})
        run: composer update --${{ matrix.deps }} --prefer-dist --no-interaction --no-progress

      - name: Create roles and database
        run: ./tests/bin/setup-db.sh

      - name: Run tests
        run: composer test

  test-postgres:
    name: "PostgreSQL ${{ matrix.pg }}"
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        pg: ['14', '15', '16', '17']

    services:
      postgres:
        image: postgres:${{ matrix.pg }}
        env:
          POSTGRES_PASSWORD: postgres
        ports:
          - 5432:5432
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: pdo, pdo_pgsql
          coverage: none

      - name: Constrain to Laravel 12
        run: composer require --dev "orchestra/testbench:^10.0" --no-interaction --no-update

      - name: Install dependencies
        run: composer update --prefer-stable --prefer-dist --no-interaction --no-progress

      - name: Create roles and database
        run: ./tests/bin/setup-db.sh

      - name: Run tests
        run: composer test

  static-analysis:
    name: Static analysis (PHPStan)
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none

      - name: Install dependencies
        run: composer update --prefer-dist --no-interaction --no-progress

      - name: Analyse
        run: composer lint

  format:
    name: Format check (Pint)
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none

      - name: Install dependencies
        run: composer update --prefer-dist --no-interaction --no-progress

      - name: Check formatting
        run: vendor/bin/pint --test
```

- [ ] **Step 2: Validate YAML syntax locally**

Run:
```bash
python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/ci.yml')); print('YAML OK')"
```
Expected: `YAML OK`.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: add version matrix (compat plane + Postgres 14-18)

test job: PHP 8.2-8.5 x Laravel 11/12/13 (prefer-stable) plus prefer-lowest
at each line's floor PHP, against Postgres 18, Laravel line pinned per cell
via a testbench constraint. test-postgres job: PG 14-17 at PHP 8.4 / L12.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 4: Push and watch CI until green**

```bash
git push
gh run watch --exit-status $(gh run list --branch main --limit 1 --json databaseId --jq '.[0].databaseId')
```
Expected: all 14 `test` cells, 4 `test-postgres` cells, `static-analysis`, and `format` pass.

- [ ] **Step 5: If any cell is red, triage against the risk list**

Likely failure modes and fixes:
- **prefer-lowest resolution conflict** on a line → the floor of some dev dep doesn't support that Laravel/PHP; widen that dep's floor in `composer.json` (smallest bump that resolves) and re-commit.
- **PHP 8.5 deprecation warnings failing PHPUnit** → confirm locally on 8.5 (Task 1 already ran the suite on 8.5); if a dep emits deprecations, they should already have surfaced locally.
- **`setup-db.sh` fails on PG 14** → check the psql error in the job log; the script is expected version-agnostic. Do not special-case unless the log proves a real incompatibility.

Fix, commit, push, re-watch until green before moving on.

---

## Task 3: Add the `pgbouncer` job (un-skip the pooling tests)

**Files:**
- Modify: `.github/workflows/ci.yml` (append a `pgbouncer` job)

**Interfaces:**
- Consumes: the green workflow from Task 2.
- Produces: a `pgbouncer` job whose run of `composer test` executes `PgBouncerTest` and `CrossWorkerLeakageTest`'s row-level case **not skipped**.

- [ ] **Step 1: Append the `pgbouncer` job to `.github/workflows/ci.yml`**

Add this job under `jobs:` (after `format`):

```yaml
  pgbouncer:
    name: PgBouncer (transaction pooling)
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:18
        env:
          POSTGRES_PASSWORD: postgres
        ports:
          - 5432:5432
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5
      pgbouncer:
        image: edoburu/pgbouncer:latest
        env:
          DB_HOST: postgres
          DB_PORT: 5432
          DB_USER: rls_app
          DB_PASSWORD: secret
          DB_NAME: rls_test
          POOL_MODE: transaction
          AUTH_TYPE: scram-sha-256
          MAX_CLIENT_CONN: 100
          DEFAULT_POOL_SIZE: 20
        ports:
          - 6432:5432

    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: pdo, pdo_pgsql
          coverage: none

      - name: Install dependencies
        run: composer update --prefer-stable --prefer-dist --no-interaction --no-progress

      - name: Create roles and database
        run: ./tests/bin/setup-db.sh

      - name: Run tests
        run: composer test
```

Note on wiring: `postgres` and `pgbouncer` are both service containers on the same job network, so `DB_HOST: postgres` resolves to the Postgres container on its internal `5432`. The runner reaches Postgres via mapped `5432` (for `setup-db.sh`) and PgBouncer via mapped `6432` (what `PgBouncerTest` connects to). PgBouncer connects to Postgres lazily on the first client connection, which happens at test time — after `setup-db.sh` has created `rls_app` — so boot order is safe.

- [ ] **Step 2: Validate YAML syntax**

```bash
python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/ci.yml')); print('YAML OK')"
```
Expected: `YAML OK`.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: add PgBouncer job to run the gated pooling tests

Stands up edoburu/pgbouncer in transaction mode in front of postgres:18 as
a second service container so PgBouncerTest and CrossWorkerLeakageTest's
row-level case run in CI instead of skipping on unreachable :6432.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 4: Push and watch the `pgbouncer` job**

```bash
git push
gh run watch --exit-status $(gh run list --branch main --limit 1 --json databaseId --jq '.[0].databaseId')
```
Expected: `pgbouncer` job green.

- [ ] **Step 5: Confirm the gated tests actually RAN (not skipped)**

Inspect the `pgbouncer` job's test output for the PgBouncer testdox lines:
```bash
gh run view $(gh run list --branch main --limit 1 --json databaseId --jq '.[0].databaseId') --log --job "$(gh run view $(gh run list --branch main --limit 1 --json databaseId --jq '.[0].databaseId') --json jobs --jq '.jobs[] | select(.name=="PgBouncer (transaction pooling)") | .databaseId')" | grep -iE "PgBouncer|Transaction-local context reaches|through PgBouncer|skipped"
```
Expected: the PgBouncer testdox lines appear as **passed**, with **no** "PgBouncer not reachable … skipped" line. If they still skip, PgBouncer isn't reachable on `6432` — debug the service (see Step 6).

- [ ] **Step 6: If PgBouncer auth/reachability fails, fall back to md5**

If the job log shows SCRAM auth failure or connection refused from PgBouncer:
- Try `AUTH_TYPE: md5` (edit the env in the `pgbouncer` service), commit, push, re-watch.
- If still failing, add a wait-for-pgbouncer step before the tests:
  ```yaml
      - name: Wait for PgBouncer
        run: |
          for i in $(seq 1 30); do
            PGPASSWORD=secret psql -h 127.0.0.1 -p 6432 -U rls_app -d rls_test -c 'select 1' && break
            sleep 2
          done
  ```
  Commit, push, re-watch. Iterate only against real CI logs — do not guess.

---

## Task 4: Docs, badge, branch protection, memory

**Files:**
- Modify: `docs/MILESTONES.md` (§ Status table + § C block)
- Verify: `README.md:3` (CI badge — already present and correct)
- Modify: memory `poc-state.md` and `MEMORY.md`

**Interfaces:**
- Consumes: the green five-job workflow from Tasks 2–3.

- [ ] **Step 1: Verify the README CI badge**

The badge already exists at `README.md:3`:
```
[![CI](https://github.com/Radiergummi/laravel-rls/actions/workflows/ci.yml/badge.svg)](https://github.com/Radiergummi/laravel-rls/actions/workflows/ci.yml)
```
Confirm it renders green on the repo front page. No edit needed unless it's stale.

- [ ] **Step 2: Mark Milestone C done in `docs/MILESTONES.md`**

In the Status table (line ~32), change the C row from `🟡 partial` to:
```
| **C — Version-matrix CI** | ✅ **done** | Two-plane matrix green on push/PR: compat plane (PHP 8.2–8.5 × Laravel 11/12/13, prefer-stable + prefer-lowest-at-floor) on Postgres 18, plus a Postgres 14–17 plane; a dedicated PgBouncer job runs the previously-skipped pooling tests. PHPStan + Pint jobs unchanged. Perf-regression job deliberately deferred (see below). |
```

In the § "Milestone C" block, update the `> **Status:**` blockquote to reflect done, and under "Done when" add a line noting the perf-regression job was **deliberately deferred, not silently dropped**: `BenchSmokeTest` already exercises `bench/run.php` end-to-end in the `test` job, and percentile gating on shared GitHub runners is too noisy to gate on; `bench/baseline.json` remains a local regression reference.

- [ ] **Step 3: Commit docs**

```bash
git add docs/MILESTONES.md
git commit -m "docs(ci): mark Milestone C done; note perf job deferred

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
git push
```

- [ ] **Step 4: Make green required to merge on `main` (needs the user's go-ahead)**

This is a repo-admin action. Present the command to the user and run it only with their go-ahead:
```bash
gh api -X PUT repos/Radiergummi/laravel-rls/branches/main/protection \
  --input - <<'JSON'
{
  "required_status_checks": {
    "strict": true,
    "contexts": []
  },
  "enforce_admins": false,
  "required_pull_request_reviews": null,
  "restrictions": null
}
JSON
```
Note: leaving `contexts: []` with `strict: true` requires branches to be up to date but doesn't pin specific check names; if the user wants specific required checks, list the job names (e.g. the `test` cell names) in `contexts`. Confirm the exact desired policy with the user before running.

- [ ] **Step 5: Update the memory**

Update `poc-state.md` to record Milestone C done (two-plane matrix + PgBouncer job un-skips the gated tests + perf job deferred; main pushed & CI green), and refresh the one-line pointer in `MEMORY.md`. Convert any relative dates to absolute (2026-07-07).

---

## Self-Review

**Spec coverage:**
- composer.json bump → Task 1. ✓
- Compat plane (PHP×Laravel×deps @ PG18) → Task 2 `test` job. ✓
- PG plane (14–17) → Task 2 `test-postgres` job. ✓
- PgBouncer dedicated job → Task 3. ✓
- static-analysis + format unchanged → Task 2. ✓
- Perf job dropped, noted not silently → Task 4 Step 2. ✓
- Badge + branch protection → Task 4 Steps 1, 4. ✓
- MILESTONES.md + memory update → Task 4 Steps 2, 5. ✓

**Placeholder scan:** No TBD/TODO; every code/YAML step shows full content; triage steps name concrete failure modes and fixes. ✓

**Type/name consistency:** Laravel↔testbench map (`^9.0`/`^10.0`/`^11.0`) identical across Global Constraints, Task 1, and Task 2. Job names (`test`, `test-postgres`, `pgbouncer`, `static-analysis`, `format`) consistent between Tasks 2–3 and the branch-protection note. Ports (`5432` direct, `6432` bouncer) consistent with `PgBouncerTest` and `setup-pgbouncer.sh`. ✓
