# Owner-mode bypass unification — implementation plan

**Why.** The owner-mode isolation predicate `rls.bypass() or "<col>" = rls.context('<key>')::<type>`
forces a sequential scan on every scoped read (the `OR rls.bypass()` defeats the
scoping-column index) — ~30× slower at 100k rows, worse linearly with size. See the
P0 item in [`../../BACKLOG.md`](../../BACKLOG.md). Empirically (PG 18.4): the equality-only
predicate is index-friendly and correct; in-band bypass under FORCE is impossible
(`SET LOCAL row_security = off` errors); a `BYPASSRLS` role bypasses cleanly.

**Decision (2026-07-05).** Unify bypass onto an admin connection for *both* role
models, and remove the GUC/predicate bypass machinery entirely. After this:
`withoutIsolation()`/`system()` route to the configured `admin_connection`; with
none configured they throw `AdminConnectionRequired`. Owner mode's zero-infra
in-band bypass is gone (accepted tradeoff). Restricted mode is already this way.

---

## Source changes

1. **`Schema/RlsSchemaMacros::isolatedBy`** — drop the owner-mode `rls.bypass() OR`
   wrapper. Predicate is always `"<col>" = rls.context('<key>')::<type>`. Only
   remaining owner/restricted difference: owner still emits `force row level security`.

2. **`Support/RlsFunctions`** — remove the `rls.bypass()` function statement. Keep
   `rls.context()` and the `create schema rls`.

3. **`Database/HandlesRlsContext`**
   - `applyRlsContext()`: remove the `app.bypass` `set_config` write and the
     `!$context->isBypass()` gate — always set the current context's values.
   - `resetSessionContext()`: remove the `app.bypass` blank.
   - `guardRlsBoundary()`: replace `$manager->current()?->isBypass()` with
     `$manager->isBypassing()` (new in-flight flag — see #6).

4. **`RlsServiceProvider::boot`**
   - Install the bypass handler **unconditionally** (delete the
     `if (role_model === 'restricted')` guard). The handler already throws
     `AdminConnectionRequired` when `admin_connection` is null.
   - The handler toggles `RlsManager::beginBypass()/endBypass()` (try/finally)
     around the connection swap so the guard flag is set for the callback's duration.
   - Delete the `Context::dehydrating(... stripBypassOnDehydrate ...)` hook.

5. **`Context/RlsContext`** — remove `bypass()`, `isBypass()`, the `$bypass`/`$reason`
   fields, and `reason()`. `RlsContext` becomes a pure value bag (`make`, `values`,
   `get`, `has`, `with`).

6. **`Context/RlsManager`**
   - Remove `stripBypassOnDehydrate()`.
   - `withoutIsolation()`: dispatch `RlsBypassed`, then require the handler — if
     `$this->bypassHandler === null`, throw `AdminConnectionRequired`. Remove the
     `enter(RlsContext::bypass(...))` fallback.
   - Add a minimal in-flight bypass signal: `private bool $bypassing`,
     `beginBypass()`, `endBypass()`, `isBypassing()`. (Replaces the stack-based
     bypass context the guard used to read.)

7. **`Exceptions/AdminConnectionRequired`** — generalise the message (drop "in
   restricted mode"; bypass now needs an admin connection in any mode).

8. **`config/rls.php`** — update the `admin_connection` comment: required for
   `system()`/`withoutIsolation()` in **both** role models.

9. **`database/migrations` + `tests/Fixtures/.../install_rls_functions`** — no code
   change (both call `RlsFunctions::install()`), but they now install one fewer
   function. Verify no migration hard-codes `rls.bypass()`.

## Test-harness changes

- **`tests/bin/setup-db.sh`** — add a `BYPASSRLS` role (e.g. `rls_bypass`, LOGIN,
  NOSUPERUSER, BYPASSRLS) and grant it. This is the owner-mode admin connection.

- **Seed-within-context refactor** (keeps `RefreshDatabase`): in `TenantIsolationTest`,
  `TestingHelpersTest`, `IsolationKeyAgnosticTest`, replace
  `withoutIsolation('seed', ...)` cross-tenant seeding with per-tenant
  `isolateTo([...], fn () => Model::factory()->create([...]))` — WITH CHECK permits
  same-tenant writes, so no bypass is needed to seed.

- **Owner-mode bypass read test** (`TenantIsolationTest::bypass_sees_all_tenants`) —
  move to a dedicated test modelled on `RestrictedIsolationTest`: owner role_model,
  a `pgsql_admin` connection as `rls_bypass`, committed seed data, manual teardown,
  no `RefreshDatabase`. Asserts `system()` sees all tenants and hard-fails without an
  admin connection.

- **Remove now-invalid tests:**
  - `RlsFunctionsTest` — the two `rls.bypass()` tests.
  - `ContextInjectionTest` — the `rls.bypass()` GUC-injection assertions (lines ~58-62).
  - `RestrictedIsolationTest::restricted_role_cannot_self_escape_via_bypass_guc` —
    moot (no bypass GUC/clause exists). Replace with a note or a check that setting
    `app.bypass` is simply inert (optional).
  - `RlsContextTest` — the `bypass()` unit test.
  - `ContextBackingTest` — the bypass dehydrate/strip assertions.
  - `QueuedJobContextTest` — any bypass-stripped-at-dehydrate assertion.

- **Update:**
  - `RlsManagerTest::withoutIsolation() establishes a bypass context` → assert it
    routes to the handler / sets `isBypassing()` for the callback duration and throws
    without a handler. Keep the `RlsBypassed` event test.
  - `PolicyDslTest::owner-mode predicate includes the bypass clause` → assert the
    predicate is equality-only (no `rls.bypass()`).
  - `BypassObservabilityTest` — give it an `admin_connection` (or assert the event/log
    fire before the `AdminConnectionRequired` throw). Event + log still fire on dispatch.
  - `FailLoudGuardTest::Query inside Bypass does not throw` — keep; now backed by the
    `isBypassing()` flag. May need an admin connection for the bypass call to complete.
  - `tests/Fixtures/Audit/BypassSample.php` — unchanged (call sites for `rls:audit`
    scanning; they don't execute).

## Docs

- `README.md` — owner-mode bypass now needs an admin connection; update the bypass
  row and Key findings. `docs/CONTINUE.md` — update the mental model.
- Flip the P0 BACKLOG item to `[x]` with the resolution.

## Verification loop

- `composer lint` (phpstan) clean after each source file.
- `vendor/bin/phpunit` green — the suite (real PG 18) is the success criterion.
- Re-run the spike's `EXPLAIN` idea against a package-generated `documents` table to
  confirm the shipped predicate is now a Bitmap Index Scan (the whole point).
- `composer format`.

## Notes / risks

- Breaking change: any app relying on owner-mode in-band bypass must configure an
  `admin_connection` (a `BYPASSRLS` role). Call this out in the changelog/README.
- The in-flight bypass flag lives on the singleton `RlsManager`; `beginBypass`/`endBypass`
  must be try/finally-safe so an exception in the callback can't leave it stuck on.
