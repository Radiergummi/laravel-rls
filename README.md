# Postgres Row-Level Security (RLS) for Laravel

Enable isolation on a table in a migration, tell the package how to derive the current scope (a tenant, an organization,
a region, or any dimension you choose), and PostgreSQL confines every read and write to that scope. A forgotten
`where tenant_id = ?` can no longer leak data, because the filter lives in the database, not in your query builder.

> **Status.** This is a proof of concept: Right now the mechanism works end to end against real PostgreSQL, and is
> covered by tests, including transaction pooling (PgBouncer), a live `queue:work` cycle, read replicas, and two
> distinct database roles.  
> However, the package is still in early development. The API may change, and the implementation is still being hardened
> against edge cases. Most importantly, it has neither been audited nor reviewed for security. Use it at your own risk,
> and do not run it in production yet!

## What problem it solves

In a multi-tenant application the correctness of every query depends on remembering to scope it. One missing `where`
clause, one raw query, one eager-loaded relation without a constraint, and one tenant sees another tenant's rows.
Scoping in the application is a promise you have to keep on every line of data access, forever.

Row-Level Security moves that promise into PostgreSQL. A policy on the table filters rows by a value the database reads
from the current connection, so isolation holds regardless of how the query was written, including raw SQL run through
the same connection. This package wires that mechanism into Laravel: a migration helper to attach the policy, a place to
declare where the scope comes from, and transparent injection of the scope on every request, job, and command.

Note, however, that this package is not a tenancy framework: laravel-rls only sets up the database to enforce tenant
constraints on all queries you run, but it does not mandate how you identify tenants, route requests, or manage
per-tenant resources. If you already use a tenancy package,
read [Using it beneath a tenancy package](#using-it-beneath-a-tenancy-package).

## Requirements

- PHP 8.2 or newer
- Laravel 11 or newer
- PostgreSQL (RLS has existed since 9.5; the suite runs against 18)
- A non-superuser database role. Superusers and roles with `BYPASSRLS` skip every policy, so the role your application
  connects as must be an ordinary role, or isolation is silently a no-op.

## Installation

```bash
composer require radiergummi/laravel-rls
php artisan rls:install
```

`rls:install` publishes three things:

- `config/rls.php`, the configuration.
- A migration that installs the `rls.*` SQL helper functions.
- `app/Providers/RlsServiceProvider.php`, the one place your app configures RLS.

If you don't have service provider discovery enabled, register the published provider in `bootstrap/providers.php` and
run the migration:

```php
// bootstrap/providers.php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\RlsServiceProvider::class,
];
```

```bash
php artisan migrate
```

## Usage

There are three steps: declare where the scope comes from, isolate your tables, and let the package inject the scope for
you. After that, ordinary Eloquent and query-builder code is scoped without any per-query changes.

### 1. Declare the scope

The published `RlsServiceProvider` is where you name your isolation keys and map the authenticated user to their scope
values:

```php
class RlsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Declare your isolation keys. This enables value validation and typed
        // SQL helpers (rls.tenant_id()) generated from the declared type.
        Rls::defineContext(fn (ContextSchema $schema) => $schema->uuid('tenant_id'));

        // Map an authenticated identity to its scope. Called on Laravel's
        // Authenticated event, so a logged-in request establishes context on
        // its own. Return an array of isolation key => value.
        Rls::resolveContextUsing(fn ($user) => ['tenant_id' => $user->tenant_id]);
    }
}
```

### 2. Isolate your tables

In the migration for a scoped table, call `isolatedBy()` with the scoping column:

```php
Schema::create('documents', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->string('title');

    $table->isolatedBy('tenant_id');
});
```

`isolatedBy()` enables RLS on the table and attaches two policies: a permissive base policy and a restrictive isolation
policy whose predicate is `tenant_id = rls.context('tenant_id')`. The predicate is a plain equality test, so an index on
the scoping column is used normally.

Optionally, default the column to the current scope, so an insert that omits it is filled in and can never be set to the
wrong value:

```php
$table->isolatedBy('tenant_id')->withDefault();
```

### 3. Query normally

With the scope declared and the table isolated, nothing else changes. A logged-in request has its context established
from the `Authenticated` event, and every query the connection runs is confined to that tenant:

```php
Document::all();            // only the current tenant's rows
Document::find($id);        // null if that id belongs to another tenant
Document::create([...]);    // rejected by WITH CHECK if it would write another tenant's row
```

With no context set, an isolated table returns zero rows and rejects writes. Isolation fails closed, not open.

### Establishing context outside a request

When there is no authenticated user to derive the scope from (a queued job, a console command, a seeder, a test), set
the context explicitly. `Rls::isolateTo()` pushes a scope for the duration of a callback and restores the previous one
afterwards, including on exceptions:

```php
Rls::isolateTo(['tenant_id' => $tenant->id], function () {
    Document::create([...]);   // scoped to $tenant
});
```

Context set this way is captured into the job payload when you dispatch, so a job runs under the same scope as the
request that queued it.

### Bypassing isolation deliberately

Some work is legitimately cross-tenant: a nightly export, an admin dashboard, a background reconciliation.
`Rls::withoutIsolation()` (and its alias `Rls::system()`) runs a callback with isolation lifted. It requires a reason,
and every call is logged so bypasses stay visible in production:

```php
Rls::withoutIsolation('nightly-export', fn () => Document::all());
```

Bypass is not an in-band escape hatch. It routes the work to a separate, privileged database connection (a role with
`BYPASSRLS`) that you configure as `admin_connection`. If no admin connection is configured, bypass hard-fails rather
than silently running unscoped. This is deliberate: there is no SQL clause an attacker or a stray query could set to
turn isolation off.

### More than one dimension

`tenant_id` is only the running example. The context is a set of named dimensions; declare whatever your application
scopes by, of whatever type, and the rest behaves identically:

```php
Rls::defineContext(fn (ContextSchema $schema) => $schema
    ->uuid('org_id')
    ->integer('region_id'));

// in a migration
$table->isolatedBy('org_id');
$table->isolatedBy('region_id', type: 'integer');

// establishing a compound scope
Rls::isolateTo(['org_id' => $org->id, 'region_id' => 3], fn () => Report::all());
```

## How it works

Policies read the current scope from a PostgreSQL configuration parameter (a GUC) via the
`rls.context()` helper function. The package sets that parameter for you, so your policies and your application never
touch `set_config()` or `SET LOCAL` directly.

By default (the `transaction` strategy) the scope is set transaction-locally with `set_config(...,
true)` and a bound parameter. Any query you run outside an explicit transaction is wrapped in one automatically so the
scope can be injected and then discarded when the transaction ends. This is safe under transaction-level connection
pooling (PgBouncer), because the scope never outlives the transaction and so cannot bleed into the next client on a
shared connection.

The scope value is always a bound parameter, never string-interpolated, so an isolation value that happens to contain
SQL is inert.

Because the filter lives in the table's policy, it applies to every access path through that connection: Eloquent, the
query builder, raw `DB::select`, joins, and subqueries. The one boundary worth understanding is that RLS confines rows
on a connection; it does not sandbox arbitrary SQL. A
`SECURITY DEFINER` function or a query deliberately run on the privileged admin connection is not subject to the
caller's scope. Those boundaries are documented rather than hidden.

## Role models

RLS treats the table owner specially, and that choice is the main security decision you make. The package supports two
models, selected by `role_model` in the config.

**`owner` (default).** Enables `FORCE ROW LEVEL SECURITY`, so policies apply even to the table owner. Your application
connects as the owner. This stops developer mistakes: a forgotten `where` clause, an unscoped relation, a raw query
written by hand. It does not contain an attacker who has obtained the application's own database credentials, because
those credentials own the tables.

**`restricted`.** Your application connects as a separate, non-owning role. Isolation then holds even against that role
directly, so it also contains a compromised application credential or a successful SQL injection, not only honest
mistakes. This is stronger and is the right choice if RLS is part of your threat model rather than only a correctness
backstop. It costs you a second role and connection to manage.

In both models, `withoutIsolation()` / `system()` route to the configured `admin_connection`; there is no in-band bypass
in either.

## Configuration

`config/rls.php`:

| Key                  | Default                 | Purpose                                                                                                                                                                          |
|----------------------|-------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `prefix`             | `app.`                  | GUC namespace the scope parameters live under.                                                                                                                                   |
| `role_model`         | `owner`                 | `owner` (FORCE, contains mistakes) or `restricted` (separate role, contains a compromised credential).                                                                           |
| `admin_connection`   | `null`                  | Privileged `BYPASSRLS` connection used by bypass. Required for bypass in both role models.                                                                                       |
| `strategy`           | `transaction`           | `transaction` (pooling-safe, default) or `session` (one fewer round-trip, needs a direct connection).                                                                            |
| `boundary`           | `wrap`                  | How a scope is applied per query: `wrap` (auto-wrap in a transaction), `explicit` (require your own transaction, else throw), or `request` (a middleware opens one per request). |
| `on_missing_context` | `closed`                | With no context on an isolated table: `closed` (database returns zero rows) or `throw` (fail loudly in PHP before querying).                                                     |
| `leak_canary`        | `log`                   | Detects a context that survived past a job or request on a long-lived worker: `log`, `throw`, or `off`. The stale context is always cleared regardless.                          |
| `connection_class`   | `RlsPostgresConnection` | Set to a class extending another package's PostgreSQL connection to compose with it.                                                                                             |

### Strategy: transaction vs. session

`transaction` is the safe default and works behind a transaction pooler. `session` sets the scope as a session parameter
instead, saving a per-transaction round-trip, but it requires a direct connection (not a transaction pooler), because a
session parameter outlives a single transaction and would otherwise leak to the next client on a shared connection. The
package blanks session parameters at each job and request boundary and re-applies them after a reconnect, but the
direct-connection requirement stands.

## Using it beneath a tenancy package

If you already use [stancl/tenancy](https://github.com/archtechx/tenancy) or
[spatie/laravel-multitenancy](https://github.com/spatie/laravel-multitenancy) for routing, identification, and
per-tenant resources, keep them. Point `resolveContextUsing()` at whatever they have already resolved, and RLS becomes
the database-level backstop underneath them:

```php
// stancl/tenancy
Rls::resolveContextUsing(fn () => tenant()
    ? ['tenant_id' => tenant()->getTenantKey()]
    : []);

// spatie/laravel-multitenancy
Rls::resolveContextUsing(fn () => Tenant::current()
    ? ['tenant_id' => Tenant::current()->getKey()]
    : []);
```

These packages switch tenants through their own events rather than Laravel's `Authenticated` event, so also re-establish
context on their tenant-initialized hook (for example stancl's
`TenancyInitialized`) with `Rls::isolateTo(...)`. A first-class bridge for this is on the backlog; the recipe above
works today.

## Commands

| Command       | Purpose                                                                                                     |
|---------------|-------------------------------------------------------------------------------------------------------------|
| `rls:install` | Publish the config, the SQL-functions migration, and the app-side `RlsServiceProvider`.                     |
| `rls:check`   | Assert that every RLS-managed table has RLS enabled and at least one policy. Runs in CI as a coverage gate. |
| `rls:audit`   | Report every `withoutIsolation()` / `system()` call site, so bypasses stay reviewable.                      |
| `rls:sync`    | Regenerate the typed `rls.<key>()` SQL helpers from the declared isolation keys.                            |

## Testing

The `InteractsWithRls` trait provides helpers for asserting isolation and a leak canary that fails a test if a context
escaped it:

```php
use Radiergummi\LaravelRls\Testing\InteractsWithRls;

class DocumentIsolationTest extends TestCase
{
    use InteractsWithRls;

    public function test_a_tenant_cannot_see_another_tenants_documents(): void
    {
        $this->assertIsolates(
            Document::class,
            isolatedBy: 'tenant_id',
            acting: $tenantA,
            cannotSee: $tenantB,
        );

        $this->assertRejectsForeignWrite(
            Document::class,
            isolatedBy: 'tenant_id',
            acting: $tenantA,
            foreign: $tenantB,
        );
    }
}
```

Note that tests must connect as a non-superuser. A superuser bypasses RLS, so isolation assertions would pass without
proving anything.

## When not to use it

- **You are not on PostgreSQL.** This package is PostgreSQL-specific and has no equivalent for MySQL or SQLite.
- **You cannot run as a non-superuser role.** On a managed database that only gives you a superuser or a `BYPASSRLS`
  role, policies are skipped and isolation is a no-op. `restricted` mode needs two roles.
- **You need to contain a compromised credential but cannot manage a second role.** The default
  `owner` mode contains mistakes, not an attacker holding your application's own credentials. That requires `restricted`
  mode; if you cannot run it, RLS is a correctness backstop rather than a security boundary, and you should size your
  trust accordingly.
- **Your isolation rule is not an equality on a column.** The policy predicate is a column-equals-scope test. Rules that
  need joins or complex logic are outside what `isolatedBy()` generates (you can still write such policies by hand, but
  that is not what this package automates).
- **Performance under RLS is not yet characterized here.** The overhead is expected to be small (an indexed equality
  plus, under the transaction strategy, one round-trip per transaction), but the measured numbers are still being
  produced (Milestone A). If you need published percentiles before adopting, wait for them.

## Development

Requires PHP 8.2+, Composer, and Docker.

```bash
composer install

# PostgreSQL plus the two roles the suite needs (rls_app owner, rls_restricted non-owner)
docker run -d --name rls-pg -e POSTGRES_PASSWORD=postgres -p 5432:5432 postgres:18
./tests/bin/setup-db.sh

composer test      # phpunit
composer lint      # phpstan
composer format    # pint

docker rm -f rls-pg
```

## Further reading

- [Design and threat model](docs/superpowers/specs/2026-07-04-laravel-postgresql-rls-design.md)
- [Milestones](docs/MILESTONES.md) (post-PoC, pre-release track)
- [Backlog](docs/BACKLOG.md)

## License

MIT. See [LICENSE](LICENSE).
