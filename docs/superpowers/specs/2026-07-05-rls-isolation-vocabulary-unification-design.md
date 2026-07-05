# Isolation vocabulary unification

**Date:** 2026-07-05
**Status:** Approved — ready for implementation plan
**Supersedes** the API naming used in the PoC docs
(`2026-07-04-laravel-postgresql-rls-design.md`, `…-poc.md`), which are retained
as historical record.

## Why

The library speaks two words for one idea. The schema side says `scopedBy()`;
the runtime/declaration side says "dimension." Neither is anchored — both are
PoC choices. Worse, the testing trait hard-codes `tenant_id` in
`actingAsTenant()` and as the default in `assertRlsIsolates` /
`assertCannotWriteAcrossTenants`, violating the library's core promise: *it must
make no assumption about the schema or tenancy layout* (BACKLOG P0).

This spec unifies the entire public surface under **one concept — isolation** —
and removes every hard-coded tenancy assumption. This is the pre-1.0 moment to
do it: no external consumers, so it is a hard rename with no aliases or
deprecation shims.

## The concept

One idea — *isolation* — in two grammatical forms:

- **noun:** an *isolation key* — the named value the database isolates on
  (`org_id`, `region_id`, `tenant_id`). Replaces "dimension."
- **verb:** *isolate* — `isolatedBy` (schema constraint), `isolateTo` (confine a
  block at runtime), `withoutIsolation` (escape hatch).

"Isolation" is collision-free (unlike "scope", which fights Eloquent query
scopes) and is literally what the library does. The sibling terms fall out of
the theme rather than being forced — the signal the anchor is right.

The **context** bag is a *distinct* concept and is deliberately left alone: it
is the active set of isolation values (backed by Laravel's `Context`), not a
synonym for a single isolation key. `defineContext()`, the `ContextSchema` type
methods (`->uuid()`, `->integer()`, …), and the `withRlsContext`-style bag
semantics keep the "context" name.

## Decisions locked during brainstorming

1. **Full unification**, not trait-only — schema + runtime + testing + docs all
   speak one vocabulary.
2. **Anchor word: isolation** (over "scope" — Eloquent collision).
3. **Setter verb: `isolateTo`** (facade and trait), over `actingAs` /
   `isolatedAs` / `actingIsolatedAs`.
4. **Assertion key label: `isolatedBy:`** (echoes the schema macro).
5. **No default isolation key anywhere** — callers always name the key
   explicitly. (Chosen over schema-derived defaults or a config knob.)
6. **Drop the single-key setter sugar** — `actingAsTenant($id)` has no
   replacement; `isolateTo(['org_id' => $id])` covers it. One verb, whole
   library.
7. **`Rls::system()` kept** — the privileged-actor sugar is orthogonal to the
   isolation-key vocabulary and carries no tenancy assumption.

## Complete rename map

### Schema (migrations)

| Before | After |
|---|---|
| `$table->scopedBy($column, $dimension = null, $type = 'uuid')` | `$table->isolatedBy($column, $key = null, $type = 'uuid')` |
| `ScopedByDefinition` class | `IsolatedByDefinition` class |
| `->withDefault()` | unchanged |

The first positional arg remains the physical table **column**; the optional
second is the **isolation key** it maps to (defaults to the column name). The
generated Postgres policy names (`{table}_{key}_isolation`) are unchanged in
form — only the internal PHP variable renames `$dimension` → `$key`.

### Runtime (facade + `RlsManager`)

| Before | After |
|---|---|
| `Rls::actingAs([...], $cb)` | `Rls::isolateTo([...], $cb)` |
| `Rls::withoutRls($reason, $cb)` | `Rls::withoutIsolation($reason, $cb)` |
| `Rls::system($reason, $cb)` | **kept** |
| `ContextSchema::dimensions()` | `ContextSchema::isolationKeys()` (internal) |

### Testing trait (`InteractsWithRls`)

| Before | After |
|---|---|
| `withRlsContext([...], $cb)` | `isolateTo([...], $cb)` |
| `actingAsTenant($id, $cb)` | **removed** |
| `withoutRls($reason, $cb)` | `withoutIsolation($reason, $cb)` |
| `assertTableProtected($table)` | `assertTableIsolated($table)` |
| `assertRlsIsolates($m, from:, cannotSee:, dimension:)` | `assertIsolates($m, isolatedBy:, acting:, cannotSee:)` |
| `assertCannotWriteAcrossTenants($m, actingAs:, tenant:, dimension:)` | `assertRejectsForeignWrite($m, isolatedBy:, acting:, foreign:)` |
| `tenantKey($v)` | `resolveKey($v)` |
| `tearDownInteractsWithRls()` | unchanged (leak canary; no assumption) |

Final assertion signatures (all keys explicit, no defaults):

```php
protected function assertTableIsolated(string $table): void;

protected function assertIsolates(
    string $modelClass,
    string $isolatedBy,   // the isolation key / model column
    mixed  $acting,       // the value we scope the acting context to
    mixed  $cannotSee,    // a foreign value whose rows must be invisible
): void;

protected function assertRejectsForeignWrite(
    string $modelClass,
    string $isolatedBy,
    mixed  $acting,
    mixed  $foreign,      // a foreign value the WITH CHECK must reject on insert
): void;

protected function isolateTo(array $context, ?Closure $callback = null): mixed;
protected function withoutIsolation(string $reason, Closure $callback): mixed;
protected function resolveKey(mixed $value): mixed;  // Model->getKey() else passthrough
```

`acting:` / `cannotSee:` / `foreign:` are plain-English argument labels (who is
acting, what must stay invisible, what write must be rejected) — not a
reintroduction of the retired `actingAs` verb.

### Exceptions & prose

| Before | After |
|---|---|
| `MissingTenantContext` | `MissingIsolationContext` (user-catchable → public surface) |
| "cross-tenant" / "tenant-less" comments, `RlsContextLeaked` message | neutral "cross-context" wording |

## File inventory

**Source (rename + call-site updates):**
- `src/Schema/RlsSchemaMacros.php` — `scopedBy`→`isolatedBy`, `$dimension`→`$key`, returns `IsolatedByDefinition`.
- `src/Schema/ScopedByDefinition.php` → **rename file/class** to `IsolatedByDefinition.php`; param + docblocks.
- `src/Context/RlsManager.php` — `actingAs`→`isolateTo`, `withoutRls`→`withoutIsolation`; `dimensions()` call site; prose.
- `src/Context/ContextSchema.php` — `dimensions()`→`isolationKeys()`; "dimension"→"isolation key" prose.
- `src/Facades/Rls.php` — `@method` annotations for the two renamed verbs.
- `src/RlsServiceProvider.php` — event-wiring / recipe call sites.
- `src/Console/AuditCommand.php` — **the bypass call-site scanner searches for the method name**; its search patterns must switch `withoutRls` → `withoutIsolation` (keep `system`).
- `src/Console/InstallCommand.php` / `src/Console/CheckCommand.php` — `scopedBy` in stub text / detection.
- `src/Database/HandlesRlsContext.php` — throws `MissingIsolationContext`.
- `src/Exceptions/MissingTenantContext.php` → **rename** to `MissingIsolationContext.php`.
- `src/Exceptions/RlsContextLeaked.php` — message prose.
- `src/Events/RlsBypassed.php`, `src/Exceptions/AdminConnectionRequired.php` — docblock/prose only.

**Tests (call-site updates, kept green):**
- `tests/Feature/`: `AgnosticDimensionHelpersTest`, `TenantIsolationTest`,
  `RestrictedIsolationTest`, `TestingHelpersTest`, `WithDefaultTest`,
  `RestrictedModeDslTest`, `PolicyDslTest`, `RlsCheckCommandTest`,
  `ContextInjectionTest`, `ContextBackingTest`, `FailLoudGuardTest`,
  `BypassObservabilityTest`.
- `tests/Unit/RlsManagerTest.php`.
- `tests/fixtures/audit/BypassSample.php` — must match the updated audit
  patterns (`withoutIsolation` / `system`).
- `tests/database/migrations/0001_01_01_000002_create_documents_table.php` —
  `scopedBy`→`isolatedBy`.

**Docs:**
- `README.md` — authoritative usage; full rename.
- `docs/CONTINUE.md`, `docs/BACKLOG.md` — code-layout references; mark the P0
  item done.
- The two dated PoC docs (`2026-07-04-*`) are **left untouched** as historical
  record; this spec supersedes their naming.

## Testing strategy

The rename is **behavior-preserving**, so the existing suite is the regression
net: source and its call sites (tests included) rename in lockstep and the full
suite stays green with an unchanged test/assertion count. No new behavior, no
new tests required — with one acceptance check:

- **`AgnosticDimensionHelpersTest`** already exercises the non-tenant (`org_id`)
  path. Under the new explicit signatures (`isolatedBy: 'org_id'`, no defaults)
  it must pass — that is the proof the P0 goal (no `tenant_id` assumption) is
  met. If any trait method still references `tenant_id`, this test's premise is
  violated.

Run: `vendor/bin/phpunit` → same green count as today (97). PHPStan level 8
(`composer lint`) must stay clean. Pint (`composer format`) applied.

## Out of scope

- `defineContext()`, `ContextSchema` type methods, Laravel `Context` backing —
  "context" is a distinct concept, kept.
- `Rls::system()` — orthogonal, kept.
- Dynamic typed accessors (`Rls::orgId()`) — already derive from declared keys.
- Any behavioral change to policy generation, guards, or session/transaction
  strategy.
