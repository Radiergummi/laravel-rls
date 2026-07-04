# laravel-rls

PostgreSQL Row-Level Security as a first-class, ergonomic Laravel feature. Set
tenant context once (`Rls::actingAs(...)`) and the database itself confines every
read *and* write — so a forgotten `WHERE tenant_id = …` can't leak data.

> **Status: proof of concept.** This is a validated PoC, not a production
> release. The architecture works end-to-end against real PostgreSQL (56 tests),
> but APIs are unstable and several production concerns are still open — see
> [`docs/BACKLOG.md`](docs/BACKLOG.md).

## What it does

```php
// Establish context — never touch GUCs, SET LOCAL, or set_config() yourself.
Rls::actingAs(['tenant_id' => $tenant->id], function () {
    Document::all();          // only this tenant's rows, enforced by Postgres
    Document::create([...]);  // WITH CHECK rejects writing another tenant's id
});

// Bypass, deliberately and visibly (reason required, always logged/auditable).
Rls::withoutRls('nightly-export', fn () => Document::all());
```

```php
// Migration DSL
Schema::create('documents', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->string('title');
    $table->scopedBy('tenant_id');   // ENABLE (+FORCE in owner mode) + RESTRICTIVE
});                                   // isolation policy with USING + WITH CHECK
```

Policies reference `rls.tenant_id()` / `rls.context('tenant_id')`, which read
transaction-local context the connection injects transparently — the same
abstraction Supabase gives you with `auth.uid()`.

## Proven in the PoC

| Area | What works |
|------|-----------|
| **Context** | Immutable, stack-based, generic named dimensions; backed by Laravel `Context` |
| **Injection** | Transaction-local `set_config()` (bound param), injected at the transaction boundary; mid-transaction re-inject makes it testable under `RefreshDatabase` |
| **Isolation** | Reads scoped, writes confined (`WITH CHECK`), fail-closed with no context; RESTRICTIVE isolation policy can't be widened by a permissive feature policy |
| **Role models** | `owner` (FORCE, zero-infra) and `restricted` (non-owner runtime role, real isolation even with FORCE off) |
| **Bypass** | `owner`: GUC escape clause; `restricted`: routes to an admin connection, hard-fails without one |
| **Jobs** | Tenant context rides a real `queue:work` dispatch→worker cycle; bypass is stripped at dehydrate |
| **Auth** | `Rls::resolveContextUsing()` + `Authenticated` event auto-establishes context |
| **Boundary modes** | `wrap` (default), `explicit` (fail-loud `MissingContextBoundary`), request middleware |
| **Strategies** | `transaction` (default, pooling-safe) and `session` (perf, direct connections) |
| **Interop** | Composes with `tpetry/laravel-postgresql-enhanced` (configurable base class + trait) |
| **Tooling** | `rls:check` (CI coverage audit), `rls:audit` (bypass call-site scan), rich test helpers + leak canary |

See [`docs/superpowers/specs/2026-07-04-laravel-postgresql-rls-design.md`](docs/superpowers/specs/2026-07-04-laravel-postgresql-rls-design.md)
for the full design and threat model.

## Configuration (`config/rls.php`)

| Key | Default | Purpose |
|-----|---------|---------|
| `prefix` | `app.` | GUC namespace |
| `role_model` | `owner` | `owner` (FORCE) or `restricted` (separate admin connection) |
| `admin_connection` | `null` | Privileged connection for bypass in restricted mode |
| `strategy` | `transaction` | `transaction` (pooling-safe) or `session` (perf) |
| `boundary` | `wrap` | `wrap`, `explicit`, or `request` |
| `on_missing_context` | `closed` | `closed` (DB zero rows) or `throw` (fail-loud in PHP) |
| `connection_class` | `RlsPostgresConnection` | Set to a class extending another package's connection to compose |

## Key findings (things the PoC taught us)

- **RLS is a no-op for the table owner unless FORCE** — and superusers/BYPASSRLS
  roles skip it entirely. Tests *must* connect as a non-superuser or they falsely
  pass. `owner` mode stops developer mistakes; only `restricted` mode contains a
  compromised credential or SQL injection.
- **A RESTRICTIVE-only table shows nothing** — you need a permissive base policy
  too. `scopedBy()` emits both (permissive `USING (true)` + RESTRICTIVE isolation).
- **Transaction-local GUCs have no "unset"** — context pop blanks keys to `''`,
  and `rls.context()` reads `nullif(..., '')` as NULL (fail-closed).
- **Composability needs care** — `run(): mixed` return type, capability detection
  (not `instanceof`), and registering the resolver in `boot()` to win the resolver
  race with packages like tpetry.
- **The fail-loud guard must skip DDL** (or it trips `migrate:fresh`), and PDO
  connects lazily (introspection needs `select()` + a re-entry flag, not `getPdo()`).

## Development

Requires PHP 8.2+, Composer, Docker.

```bash
composer install

# Postgres + roles (rls_app owner, rls_restricted non-owner)
docker run -d --name rls-pg -e POSTGRES_PASSWORD=postgres -p 5432:5432 postgres:18
./tests/bin/setup-db.sh

vendor/bin/phpunit

# tear down
docker rm -f rls-pg
```

## Documents

- [Design & threat model](docs/superpowers/specs/2026-07-04-laravel-postgresql-rls-design.md)
- [PoC implementation plan](docs/superpowers/plans/2026-07-04-laravel-rls-poc.md)
- [Backlog](docs/BACKLOG.md)
- [Continuation guide](docs/CONTINUE.md)
