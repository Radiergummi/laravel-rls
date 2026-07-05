# Isolation Vocabulary Unification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unify the library's public surface under one concept — *isolation* — and remove every hard-coded `tenant_id` assumption from the testing trait (BACKLOG P0).

**Architecture:** This is a **behavior-preserving hard rename** (pre-1.0, no external consumers, no aliases). There is no new behavior and no new test — the existing 97-test suite is the regression net. Each task renames one symbol cluster across source *and* all its call sites (src, tests, README) in lockstep, then proves the suite is still green. Tasks are ordered so every commit leaves the suite passing and PHPStan clean.

**Tech Stack:** PHP 8.5, Laravel package, PHPUnit 11, PHPStan level 8, Pint, PostgreSQL 18 (via Docker; roles set up by `tests/bin/setup-db.sh`).

## Global Constraints

- **No aliases / no deprecation shims** — hard rename, old names deleted.
- **Behavior unchanged** — generated SQL, policy names (`{table}_{key}_isolation`), guards, session/transaction strategy all identical. Only PHP identifiers and prose change.
- **Suite stays green at every commit** — `vendor/bin/phpunit` must report the same count (97 tests) after each task. Assertion count unchanged.
- **PHPStan level 8 clean** — `composer lint` passes after each task.
- **Pint formatted** — run `composer format` before each commit.
- **Test as non-superuser** — the DB env connects as `rls_app`; do not change that. Environment must be up (`docker ps` shows `rls-pg`) before running the suite.
- **`Rls::system()` is retained** everywhere — it is orthogonal to the isolation vocabulary. Never rename it.
- **Do not touch** the two dated PoC docs under `docs/superpowers/` (2026-07-04-*) — historical record.
- **Collision caution:** `actingAs` appears as the trait parameter `$actingAs` and as `$manager->actingAs(...)`; never blind-replace the bare token. Always grep and edit deliberately.

---

### Task 1: Schema macro `scopedBy` → `isolatedBy` (+ `ScopedByDefinition` → `IsolatedByDefinition`)

The macro and its fluent return type are one coupled unit.

**Files:**
- Modify: `src/Schema/RlsSchemaMacros.php`
- Rename + modify: `src/Schema/ScopedByDefinition.php` → `src/Schema/IsolatedByDefinition.php`
- Modify: `src/Console/InstallCommand.php`, `src/Console/CheckCommand.php`
- Modify (test call sites): `tests/database/migrations/0001_01_01_000002_create_documents_table.php`, `tests/Feature/WithDefaultTest.php`, `tests/Feature/TestingHelpersTest.php`, `tests/Feature/RestrictedModeDslTest.php`, `tests/Feature/PolicyDslTest.php`, `tests/Feature/RlsCheckCommandTest.php`, `tests/Feature/AgnosticDimensionHelpersTest.php`
- Modify (docs): `README.md`

**Interfaces:**
- Produces: `Blueprint::isolatedBy(string $column, ?string $key = null, string $type = 'uuid'): IsolatedByDefinition`; class `Radiergummi\LaravelRls\Schema\IsolatedByDefinition` with `->withDefault(): self`.

- [ ] **Step 1: Rename the definition class file and class**

`git mv src/Schema/ScopedByDefinition.php src/Schema/IsolatedByDefinition.php`, then in the file rename `class ScopedByDefinition` → `class IsolatedByDefinition`, the constructor param `private string $dimension` → `private string $key` (update the two `$this->dimension` uses in `withDefault()` to `$this->key`), and update the docblock example `scopedBy('tenant_id')->withDefault()` → `isolatedBy('org_id')->withDefault()`.

- [ ] **Step 2: Rename the macro in `RlsSchemaMacros.php`**

Change the macro registration from `Blueprint::macro('scopedBy', function (string $column, ?string $dimension = null, string $type = 'uuid'): ScopedByDefinition {` to use `'isolatedBy'`, param `?string $key = null`, and return type `IsolatedByDefinition`. Inside the closure rename every `$dimension` to `$key` (the `$dimension ??= $column;` line becomes `$key ??= $column;`, the two `sprintf` predicates and the policy name `'%s_%s_isolation'` use `$key`), and construct `new IsolatedByDefinition($raw, $table, $column, $key, $type)`. Update the `use`/reference if the class is imported.

- [ ] **Step 3: Update the two console commands**

In `src/Console/InstallCommand.php` and `src/Console/CheckCommand.php`, grep for `scopedBy` and replace each occurrence (stub text, comments, detection strings) with `isolatedBy`:

```bash
grep -rn "scopedBy\|ScopedByDefinition" src/Console/
```

- [ ] **Step 4: Update test migration, test call sites, and README**

Replace `scopedBy(` → `isolatedBy(` and `ScopedByDefinition` → `IsolatedByDefinition` across the test files listed above and `README.md`. Verify none remain:

```bash
grep -rn "scopedBy\|ScopedByDefinition" src/ tests/ README.md
```
Expected: no matches.

- [ ] **Step 5: Format, lint, and run the suite**

Run:
```bash
composer format && composer lint && vendor/bin/phpunit
```
Expected: Pint OK, PHPStan `[OK] No errors`, PHPUnit `OK (97 tests, 182 assertions)`.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Rename scopedBy schema macro to isolatedBy

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Runtime setter `actingAs` → `isolateTo`

**Files:**
- Modify: `src/Context/RlsManager.php` (method `actingAs` → `isolateTo`), `src/Facades/Rls.php` (`@method` annotation)
- Modify (call sites): `src/RlsServiceProvider.php`, `src/Testing/InteractsWithRls.php` (internal `Rls::actingAs` calls only — method names renamed in Task 5), any docblock prose in `src/Exceptions/MissingTenantContext.php`, `src/Exceptions/AdminConnectionRequired.php`, `src/Events/RlsBypassed.php`
- Modify (tests): `tests/Unit/RlsManagerTest.php`, `tests/Feature/TenantIsolationTest.php`, `tests/Feature/RestrictedIsolationTest.php`, `tests/Feature/ContextInjectionTest.php`, `tests/Feature/ContextBackingTest.php`, and any other file matching the grep below
- Modify (docs): `README.md`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `RlsManager::isolateTo(array $context, ?Closure $callback = null): mixed` and facade `Rls::isolateTo(...)`. The method body is unchanged — only the name changes.

- [ ] **Step 1: Rename the method on `RlsManager`**

In `src/Context/RlsManager.php`, rename `public function actingAs(array $context, ?Closure $callback = null): mixed` to `public function isolateTo(array $context, ?Closure $callback = null): mixed`. Leave the body identical.

- [ ] **Step 2: Update the facade annotation**

In `src/Facades/Rls.php`, change the line `@method static mixed actingAs(array<string, mixed> $context, ?Closure(): mixed $callback = null)` to `@method static mixed isolateTo(array<string, mixed> $context, ?Closure(): mixed $callback = null)`.

- [ ] **Step 3: Update every call site**

Find each RLS `actingAs` call (facade and `$manager->actingAs`) — the collision-safe way, since the only `actingAs` occurrences in this repo are RLS ones plus the trait parameter `$actingAs` (renamed in Task 5, leave it here):

```bash
grep -rn "actingAs" src/ tests/ README.md | grep -v '\$actingAs\|actingAsTenant'
```
Replace `actingAs(` → `isolateTo(` on each matched call (e.g. `Rls::actingAs([...])` → `Rls::isolateTo([...])`, `$manager->actingAs(...)` → `$manager->isolateTo(...)`). In `src/Testing/InteractsWithRls.php`, only the internal `Rls::actingAs(...)` calls change now — the trait's own method names are handled in Task 5.

- [ ] **Step 4: Format, lint, run the suite**

Run:
```bash
composer format && composer lint && vendor/bin/phpunit
```
Expected: Pint OK, PHPStan `[OK]`, PHPUnit `OK (97 tests, 182 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Rename Rls::actingAs to Rls::isolateTo

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Escape hatch `withoutRls` → `withoutIsolation` (+ audit scanner)

**Files:**
- Modify: `src/Context/RlsManager.php` (method), `src/Facades/Rls.php` (`@method`)
- Modify: `src/Console/AuditCommand.php` (scan regex)
- Modify (call sites): `src/Testing/InteractsWithRls.php` (internal `Rls::withoutRls` calls only), `src/RlsServiceProvider.php` if present
- Modify (tests + fixtures): `tests/fixtures/audit/BypassSample.php`, `tests/Feature/FailLoudGuardTest.php`, `tests/Feature/BypassObservabilityTest.php`, `tests/Feature/RestrictedIsolationTest.php`, `tests/Unit/RlsManagerTest.php`, `tests/Feature/TestingHelpersTest.php`, `tests/Feature/ContextInjectionTest.php`, and any file matching the grep below
- Modify (docs): `README.md`

**Interfaces:**
- Produces: `RlsManager::withoutIsolation(string $reason, Closure $callback): mixed` and facade `Rls::withoutIsolation(...)`. `Rls::system(...)` is untouched.

- [ ] **Step 1: Rename the method on `RlsManager` and the facade**

In `src/Context/RlsManager.php`, rename `public function withoutRls(string $reason, Closure $callback): mixed` to `withoutIsolation(...)` (body unchanged). In `src/Facades/Rls.php`, change `@method static mixed withoutRls(string $reason, Closure(): mixed $callback)` to `withoutIsolation(...)`. **Do not** touch the `system(...)` method or annotation.

- [ ] **Step 2: Update the audit scanner regex**

In `src/Console/AuditCommand.php`, change:
```php
$pattern = '/(?:Rls::|\$?this->|->)\s*(withoutRls|system)\s*\(/';
```
to:
```php
$pattern = '/(?:Rls::|\$?this->|->)\s*(withoutIsolation|system)\s*\(/';
```

- [ ] **Step 3: Update the audit fixture and all call sites**

`tests/fixtures/audit/BypassSample.php` is scanned by the audit tests — its `withoutRls` calls must become `withoutIsolation` so the counts still match. Then sweep all callers:

```bash
grep -rn "withoutRls" src/ tests/ README.md
```
Replace every `withoutRls(` → `withoutIsolation(`. Confirm none remain (except intentional `system`).

- [ ] **Step 4: Format, lint, run the suite**

Run:
```bash
composer format && composer lint && vendor/bin/phpunit
```
Expected: Pint OK, PHPStan `[OK]`, PHPUnit `OK (97 tests, 182 assertions)`. (The bypass-observability and audit tests are the ones that would catch a missed rename.)

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Rename withoutRls to withoutIsolation (incl. audit scanner)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: `ContextSchema::dimensions()` → `isolationKeys()` + "dimension" prose

**Files:**
- Modify: `src/Context/ContextSchema.php` (method + prose), `src/Context/RlsManager.php` (caller + prose)

**Interfaces:**
- Consumes: nothing.
- Produces: `ContextSchema::isolationKeys(): array<string, string>` (was `dimensions()`), same return shape (name => pg type).

- [ ] **Step 1: Rename the method**

In `src/Context/ContextSchema.php`, rename `public function dimensions(): array` to `public function isolationKeys(): array` (body unchanged). Update the class docblock and the `functionStatements()` docblock: "context dimensions" → "isolation keys", and the example `rls.tenant_id()` may stay as an illustrative example (it is generated from whatever key is declared — no assumption).

- [ ] **Step 2: Update the single caller in `RlsManager`**

```bash
grep -rn "dimensions()" src/
```
Replace `->dimensions()` → `->isolationKeys()` in `src/Context/RlsManager.php`. Also update any "dimension" prose in `RlsManager` that refers to the declared keys (e.g. the `__call` docblock "declared RLS context dimension" → "declared RLS isolation key").

- [ ] **Step 3: Format, lint, run the suite**

Run:
```bash
composer format && composer lint && vendor/bin/phpunit
```
Expected: Pint OK, PHPStan `[OK]`, PHPUnit `OK (97 tests, 182 assertions)`.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "Rename ContextSchema::dimensions to isolationKeys

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Rewrite the testing trait `InteractsWithRls`

The heart of the P0 fix: no method or default may reference `tenant_id`. Depends on Tasks 2 and 3 (the trait body calls `Rls::isolateTo` / `Rls::withoutIsolation`).

**Files:**
- Modify: `src/Testing/InteractsWithRls.php`
- Modify (call sites): `tests/Feature/AgnosticDimensionHelpersTest.php`, `tests/Feature/TenantIsolationTest.php`, `tests/Feature/TestingHelpersTest.php`, `tests/Feature/RestrictedIsolationTest.php`, and any file matching the grep in Step 3
- Modify (docs): `README.md`

**Interfaces:**
- Consumes: `Rls::isolateTo`, `Rls::withoutIsolation` (Tasks 2–3).
- Produces the final trait surface:
  - `isolateTo(array $context, ?Closure $callback = null): mixed`
  - `withoutIsolation(string $reason, Closure $callback): mixed`
  - `assertTableIsolated(string $table): void`
  - `assertIsolates(string $modelClass, string $isolatedBy, mixed $acting, mixed $cannotSee): void`
  - `assertRejectsForeignWrite(string $modelClass, string $isolatedBy, mixed $acting, mixed $foreign): void`
  - `resolveKey(mixed $value): mixed`
  - `tearDownInteractsWithRls(): void` (unchanged)
  - **removed:** `withRlsContext`, `actingAsTenant`, `assertTableProtected`, `assertRlsIsolates`, `assertCannotWriteAcrossTenants`, `tenantKey`.

- [ ] **Step 1: Replace the context/bypass helpers**

In `src/Testing/InteractsWithRls.php`, replace `withRlsContext` with `isolateTo`, delete `actingAsTenant` entirely, and rename `withoutRls` → `withoutIsolation`:

```php
    /**
     * @template T = mixed
     * @param array<string, mixed> $context
     * @param null|Closure(): T    $callback
     * @return T
     */
    protected function isolateTo(array $context, ?Closure $callback = null): mixed
    {
        return Rls::isolateTo($context, $callback);
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    protected function withoutIsolation(string $reason, Closure $callback): mixed
    {
        return Rls::withoutIsolation($reason, $callback);
    }
```

- [ ] **Step 2: Rename `assertTableProtected` → `assertTableIsolated`**

Keep the body identical (RLS-enabled / forced-in-owner-mode / restrictive-policy checks); only rename the method:

```php
    /** @throws ExpectationFailedException */
    protected function assertTableIsolated(string $table): void
    {
        // ... unchanged body ...
    }
```

- [ ] **Step 3: Replace the two behavioral assertions and the key resolver**

Replace `assertRlsIsolates`, `tenantKey`, and `assertCannotWriteAcrossTenants` with:

```php
    /**
     * @param class-string<Model> $modelClass
     * @param string $isolatedBy  the isolation key / model column to scope by
     */
    protected function assertIsolates(
        string $modelClass,
        string $isolatedBy,
        mixed $acting,
        mixed $cannotSee,
    ): void {
        $actingId = $this->resolveKey($acting);
        $otherId = $this->resolveKey($cannotSee);

        Rls::isolateTo([$isolatedBy => $actingId], function () use ($modelClass, $otherId, $isolatedBy) {
            $leaked = $modelClass::query()->where($isolatedBy, $otherId)->count();
            $this->assertSame(
                0,
                $leaked,
                "Rows scoped to {$otherId} are visible to the acting context",
            );
        });
    }

    protected function resolveKey(mixed $value): mixed
    {
        if (is_object($value) && method_exists($value, 'getKey')) {
            return $value->getKey();
        }

        return $value;
    }

    /**
     * @param class-string<Model> $modelClass
     * @param string $isolatedBy  the isolation key / model column to scope by
     */
    protected function assertRejectsForeignWrite(
        string $modelClass,
        string $isolatedBy,
        mixed $acting,
        mixed $foreign,
    ): void {
        $actingId = $this->resolveKey($acting);
        $foreignId = $this->resolveKey($foreign);

        Rls::isolateTo([$isolatedBy => $actingId], function () use ($modelClass, $foreignId, $isolatedBy) {
            try {
                // Run in a savepoint so the expected policy violation rolls back cleanly without
                // aborting any surrounding transaction.
                DB::transaction(static function () use ($modelClass, $foreignId, $isolatedBy) {
                    $modelClass::query()->create([$isolatedBy => $foreignId]);
                });

                $this->fail('Expected WITH CHECK to reject the cross-context write');
            } catch (QueryException $exception) {
                $this->assertStringContainsStringIgnoringCase(
                    'row-level security',
                    $exception->getMessage(),
                );
            }
        });
    }
```

- [ ] **Step 4: Update all trait call sites**

```bash
grep -rn "withRlsContext\|actingAsTenant\|assertTableProtected\|assertRlsIsolates\|assertCannotWriteAcrossTenants\|tenantKey" src/ tests/ README.md
```
Update each to the new API. Concretely:
- `$this->assertRlsIsolates(M::class, from: $a, cannotSee: $b, dimension: 'k')` → `$this->assertIsolates(M::class, isolatedBy: 'k', acting: $a, cannotSee: $b)`. Where the old call omitted `dimension:` (defaulted to `tenant_id`), pass `isolatedBy: 'tenant_id'` explicitly (e.g. in `TenantIsolationTest`).
- `$this->assertCannotWriteAcrossTenants(M::class, actingAs: $a, tenant: $b, dimension: 'k')` → `$this->assertRejectsForeignWrite(M::class, isolatedBy: 'k', acting: $a, foreign: $b)`; where `dimension:` was omitted, pass `isolatedBy: 'tenant_id'`.
- `$this->withRlsContext([...])` → `$this->isolateTo([...])`; `$this->actingAsTenant($id)` → `$this->isolateTo(['tenant_id' => $id])`.
- `$this->assertTableProtected($t)` → `$this->assertTableIsolated($t)`.

Confirm nothing stale remains:
```bash
grep -rn "withRlsContext\|actingAsTenant\|assertTableProtected\|assertRlsIsolates\|assertCannotWriteAcrossTenants\|tenantKey" src/ tests/ README.md
```
Expected: no matches.

- [ ] **Step 5: Verify the P0 acceptance and full suite**

The trait must contain **zero** `tenant_id` literals:
```bash
grep -n "tenant_id\|tenant" src/Testing/InteractsWithRls.php
```
Expected: no matches.

Then:
```bash
composer format && composer lint && vendor/bin/phpunit --filter AgnosticDimensionHelpersTest && vendor/bin/phpunit
```
Expected: `AgnosticDimensionHelpersTest` passes on the new explicit signatures (proving no tenant assumption), then full suite `OK (97 tests, 182 assertions)`.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Rewrite InteractsWithRls trait with isolation vocabulary, drop tenant_id assumptions

Closes the BACKLOG P0 item: no trait method or default references tenant_id.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: `MissingTenantContext` → `MissingIsolationContext` + cross-tenant prose

**Files:**
- Rename + modify: `src/Exceptions/MissingTenantContext.php` → `src/Exceptions/MissingIsolationContext.php`
- Modify: `src/Database/HandlesRlsContext.php` (throw + import), `src/Exceptions/RlsContextLeaked.php` (message), `src/Context/RlsManager.php` (comment prose), `src/Testing/InteractsWithRls.php` (any remaining prose)
- Modify (tests): `tests/Feature/FailLoudGuardTest.php`

**Interfaces:**
- Produces: `Radiergummi\LaravelRls\Exceptions\MissingIsolationContext` (replaces `MissingTenantContext`; same parent `RuntimeException`, same message intent).

- [ ] **Step 1: Rename the exception file and class**

`git mv src/Exceptions/MissingTenantContext.php src/Exceptions/MissingIsolationContext.php`, then rename `class MissingTenantContext` → `class MissingIsolationContext` inside it. If its message/docblock says "tenant", reword to "isolation context".

- [ ] **Step 2: Update the thrower and test**

```bash
grep -rn "MissingTenantContext" src/ tests/
```
In `src/Database/HandlesRlsContext.php` update the `use` import and the `throw new MissingTenantContext(...)`; in `tests/Feature/FailLoudGuardTest.php` update the `use` import and `expectException(MissingIsolationContext::class)`.

- [ ] **Step 3: Neutralize remaining tenant prose**

```bash
grep -rn "cross-tenant\|tenant-less" src/
```
Reword comments in `src/Context/RlsManager.php` and `src/Testing/InteractsWithRls.php` ("cross-tenant hazard" → "cross-context hazard", "tenant-less user" → "context-less user"), and the `RlsContextLeaked` message ("cross-tenant isolation hazard" → "cross-context isolation hazard").

- [ ] **Step 4: Format, lint, run the suite**

```bash
composer format && composer lint && vendor/bin/phpunit
```
Expected: Pint OK, PHPStan `[OK]`, PHPUnit `OK (97 tests, 182 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Rename MissingTenantContext to MissingIsolationContext, neutralize tenant prose

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Documentation sweep + backlog

**Files:**
- Modify: `README.md` (final consistency pass), `docs/CONTINUE.md`, `docs/BACKLOG.md`

- [ ] **Step 1: Full-tree stale-name sweep**

Confirm no renamed symbol survives outside the historical dated PoC docs:
```bash
grep -rn "scopedBy\|ScopedByDefinition\|actingAsTenant\|Rls::actingAs\|->actingAs(\|withoutRls\|assertTableProtected\|assertRlsIsolates\|assertCannotWriteAcrossTenants\|tenantKey\|MissingTenantContext\|\.dimensions()" src/ tests/ README.md docs/CONTINUE.md docs/BACKLOG.md
```
Expected: no matches. (If any appear, fix them — they were missed in earlier tasks.)

- [ ] **Step 2: Update `CONTINUE.md` code-layout references**

In `docs/CONTINUE.md`, update the `src/` code-layout block and any test-name references so they name the new symbols (e.g. `Schema/RlsSchemaMacros.php` line describing `scopedBy / enableRowLevelSecurity` → `isolatedBy / ...`; `Exceptions/` list `MissingTenantContext` → `MissingIsolationContext`).

- [ ] **Step 3: Mark the P0 backlog item done**

In `docs/BACKLOG.md`, change the `tenant_id assumptions hard-coded` item from `[ ]` to `[x]` and append a one-line note: "Resolved via the isolation-vocabulary unification (2026-07-05): trait has no `tenant_id` literal; all isolation keys are explicit." Update the P2 "Earned sugar macros" bullet that references `scopedBy` to say `isolatedBy`.

- [ ] **Step 4: Final verification**

```bash
composer lint && vendor/bin/phpunit
```
Expected: PHPStan `[OK]`, PHPUnit `OK (97 tests, 182 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Update docs and backlog for isolation vocabulary unification

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:** Every rename-map row in the spec maps to a task — schema (T1), `isolateTo` (T2), `withoutIsolation` + audit (T3), `isolationKeys` (T4), trait incl. all six method renames + `resolveKey` + dropped sugar (T5), `MissingIsolationContext` + prose (T6), docs/backlog (T7). The "not touched" list (`defineContext`, `ContextSchema` type methods, `Rls::system`, dynamic accessors) is enforced by the Global Constraints. The P0 acceptance (no `tenant_id` in the trait; `AgnosticDimensionHelpersTest` green) is Task 5 Step 5.

**Placeholder scan:** No TBD/TODO; every code step shows the actual code or the exact grep/substitution to apply. Mechanical call-site edits are specified as verified-safe grep + replace rather than blind sed (collision caution in Global Constraints).

**Type consistency:** `isolatedBy(string $column, ?string $key, string $type)`, `IsolatedByDefinition`, `isolateTo(array, ?Closure)`, `withoutIsolation(string, Closure)`, `isolationKeys()`, `assertIsolates(string, string $isolatedBy, mixed $acting, mixed $cannotSee)`, `assertRejectsForeignWrite(string, string $isolatedBy, mixed $acting, mixed $foreign)`, `resolveKey(mixed)`, `MissingIsolationContext` — names and signatures are identical across the Interfaces blocks and the code steps that use them.
