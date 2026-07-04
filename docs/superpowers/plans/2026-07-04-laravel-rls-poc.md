# Laravel PostgreSQL RLS — PoC Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A working end-to-end proof of concept that PostgreSQL RLS enforces tenant isolation transparently in Laravel — set context via `Rls::actingAs()`, and the database silently confines reads *and* writes to that tenant.

**Architecture:** A custom `PostgresConnection` subclass injects transaction-local `set_config()` at the transaction boundary; a stack-based `RlsManager` holds a generic named-dimension context; a Blueprint macro (`scopedBy`) generates RESTRICTIVE isolation policies (+ a permissive base) that reference `rls.context()`/`rls.bypass()` SQL helpers. Owner-mode + FORCE + `transaction` strategy + `wrap` boundary only. Verified against real Postgres 18 in Docker.

**Tech Stack:** PHP 8.2+ (8.5 locally), Laravel 11/12 (`illuminate/*`), Orchestra Testbench, PHPUnit 11, PostgreSQL 18, PDO pgsql.

## Global Constraints

- **Namespace:** `Radiergummi\Rls\` → `src/`; tests `Radiergummi\Rls\Tests\` → `tests/`. Copy verbatim.
- **PHP floor:** `^8.2`. Laravel: `^11.0|^12.0`.
- **PoC scope — owner mode only:** `role_model = owner`, FORCE always on, strategy = `transaction`, boundary = `wrap`. NO restricted mode, NO extension install, NO session strategy, NO queue/Octane integration, NO HTTP middleware, NO app-layer fail-loud guard (rely on DB fail-closed), NO declared-schema typed helpers (use generic `rls.context('key')`).
- **Test DB identity:** tests connect as **non-superuser** role `rls_app` (superusers bypass RLS even with FORCE). DB `rls_test` owned by `rls_app`.
- **SQL helper contract:** `rls.context(key text)` = `nullif(current_setting('app.'||key, true), '')`, `STABLE`. `rls.bypass()` = `coalesce(nullif(current_setting('app.bypass', true), ''), 'off')::boolean`, `STABLE`. GUC prefix is `app.`. Empty-string means "unset" (so `::uuid` casts of missing context yield NULL, not a cast error).
- **Bypass API:** closure-only, reason-required: `Rls::withoutRls(string $reason, Closure $cb)`.
- **Commit style:** conventional commits (`feat:`, `test:`, `chore:`). Commit after each task.

---

## File Structure

```
composer.json                              # package + dev deps, autoload
phpunit.xml                                # test env (pgsql connection to Docker)
config/rls.php                             # config defaults (boundary, prefix, role_model)
src/
  RlsServiceProvider.php                   # binds manager, registers connection resolver + macros
  Facades/Rls.php                          # facade over 'rls'
  Context/RlsContext.php                   # immutable context value object
  Context/RlsManager.php                   # stack, actingAs, bypass, active-txn sync
  Database/HandlesRlsContext.php           # trait: beginTransaction + run overrides
  Database/RlsPostgresConnection.php       # PostgresConnection + trait
  Schema/RlsSchemaMacros.php               # registers Blueprint macros (scopedBy, enable/force RLS)
  Support/RlsFunctions.php                 # single-sourced SQL for rls.* helpers
  Testing/InteractsWithRls.php             # test trait: helpers + assertions + leak canary
tests/
  TestCase.php                             # Testbench base, pgsql config, loads migrations
  bin/setup-db.sh                          # creates rls_app role + rls_test db (superuser)
  database/migrations/
    0001_01_01_000000_install_rls_functions.php
    0001_01_01_000001_create_tenants_table.php
    0001_01_01_000002_create_documents_table.php
  Models/Tenant.php
  Models/Document.php
  database/factories/TenantFactory.php
  database/factories/DocumentFactory.php
  Unit/RlsContextTest.php
  Unit/RlsManagerTest.php
  Feature/RlsFunctionsTest.php
  Feature/ContextInjectionTest.php
  Feature/PolicyDslTest.php
  Feature/TestingHelpersTest.php
  Feature/TenantIsolationTest.php
```

---

### Task 1: Package skeleton + Postgres test harness

**Files:**
- Create: `composer.json`, `phpunit.xml`, `config/rls.php`, `src/RlsServiceProvider.php`, `tests/TestCase.php`, `tests/bin/setup-db.sh`
- Test: `tests/Feature/HarnessTest.php` (temporary smoke test, deleted in Step 8)

**Interfaces:**
- Produces: `Radiergummi\Rls\RlsServiceProvider` (empty register/boot for now); `Radiergummi\Rls\Tests\TestCase` base class configuring a `pgsql` connection named `pgsql` to `rls_test` as `rls_app`.

- [ ] **Step 1: Initialize git and Docker Postgres**

```bash
cd /Users/moritz/Projects/laravel-rls
git init
docker run -d --name rls-pg -e POSTGRES_PASSWORD=postgres -p 5432:5432 postgres:18
# wait until ready
until PGPASSWORD=postgres psql -h 127.0.0.1 -U postgres -c 'select 1' >/dev/null 2>&1; do sleep 1; done
echo "postgres up"
```

- [ ] **Step 2: Create the non-superuser app role and database**

Create `tests/bin/setup-db.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
export PGPASSWORD=postgres
PSQL="psql -h 127.0.0.1 -U postgres -v ON_ERROR_STOP=1"
$PSQL -c "DROP DATABASE IF EXISTS rls_test;"
$PSQL -c "DROP ROLE IF EXISTS rls_app;"
$PSQL -c "CREATE ROLE rls_app LOGIN PASSWORD 'secret' NOSUPERUSER;"
$PSQL -c "CREATE DATABASE rls_test OWNER rls_app;"
echo "rls_app + rls_test ready"
```

Run it:

```bash
chmod +x tests/bin/setup-db.sh && ./tests/bin/setup-db.sh
```

Expected: `rls_app + rls_test ready`

- [ ] **Step 3: Write composer.json**

```json
{
    "name": "radiergummi/laravel-rls",
    "description": "PostgreSQL Row-Level Security for Laravel",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "illuminate/support": "^11.0|^12.0",
        "illuminate/database": "^11.0|^12.0",
        "illuminate/contracts": "^11.0|^12.0"
    },
    "require-dev": {
        "orchestra/testbench": "^9.0|^10.0",
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": { "Radiergummi\\Rls\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Radiergummi\\Rls\\Tests\\": "tests/" }
    },
    "extra": {
        "laravel": {
            "providers": ["Radiergummi\\Rls\\RlsServiceProvider"]
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

Run: `composer install`
Expected: dependencies install, `vendor/` created.

- [ ] **Step 4: Write config/rls.php**

```php
<?php

return [
    'prefix' => 'app.',
    'role_model' => 'owner',
    'strategy' => 'transaction',
    'boundary' => 'wrap',
    'connection_class' => \Radiergummi\Rls\Database\RlsPostgresConnection::class,
];
```

- [ ] **Step 5: Write the empty service provider**

`src/RlsServiceProvider.php`:

```php
<?php

namespace Radiergummi\Rls;

use Illuminate\Support\ServiceProvider;

class RlsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rls.php', 'rls');
    }

    public function boot(): void
    {
        //
    }
}
```

- [ ] **Step 6: Write the Testbench base TestCase**

`tests/TestCase.php`:

```php
<?php

namespace Radiergummi\Rls\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Radiergummi\Rls\RlsServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [RlsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'rls_test',
            'username' => 'rls_app',
            'password' => 'secret',
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }
}
```

- [ ] **Step 7: Write phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Package">
            <directory>tests/Unit</directory>
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 8: Write and run a temporary smoke test**

`tests/Feature/HarnessTest.php`:

```php
<?php

namespace Radiergummi\Rls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Radiergummi\Rls\Tests\TestCase;

class HarnessTest extends TestCase
{
    public function test_connects_to_postgres_as_rls_app(): void
    {
        $this->assertSame('rls_app', DB::selectOne('select current_user as u')->u);
        $this->assertFalse((bool) DB::selectOne('select usesuper from pg_user where usename = current_user')->usesuper);
    }
}
```

Run: `vendor/bin/phpunit --filter test_connects_to_postgres_as_rls_app`
Expected: PASS — confirms non-superuser connection (RLS will actually apply).

- [ ] **Step 9: Delete the smoke test and commit**

```bash
rm tests/Feature/HarnessTest.php
git add -A
git commit -m "chore: package skeleton and Postgres test harness"
```

---

### Task 2: RlsContext immutable value object

**Files:**
- Create: `src/Context/RlsContext.php`
- Test: `tests/Unit/RlsContextTest.php`

**Interfaces:**
- Produces: `RlsContext` with static `make(array $values): self`, static `bypass(string $reason): self`, `values(): array`, `get(string $key): mixed`, `has(string $key): bool`, `with(array $values): self`, `isBypass(): bool`, `reason(): ?string`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/RlsContextTest.php`:

```php
<?php

namespace Radiergummi\Rls\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Radiergummi\Rls\Context\RlsContext;

class RlsContextTest extends TestCase
{
    public function test_holds_and_reads_values(): void
    {
        $c = RlsContext::make(['tenant_id' => 'abc', 'user_id' => 7]);
        $this->assertSame('abc', $c->get('tenant_id'));
        $this->assertSame(7, $c->get('user_id'));
        $this->assertTrue($c->has('tenant_id'));
        $this->assertFalse($c->has('missing'));
        $this->assertNull($c->get('missing'));
        $this->assertFalse($c->isBypass());
    }

    public function test_with_returns_new_instance_without_mutating(): void
    {
        $a = RlsContext::make(['tenant_id' => '1']);
        $b = $a->with(['tenant_id' => '2', 'x' => 'y']);
        $this->assertSame('1', $a->get('tenant_id'));
        $this->assertSame('2', $b->get('tenant_id'));
        $this->assertSame('y', $b->get('x'));
        $this->assertNotSame($a, $b);
    }

    public function test_bypass_context(): void
    {
        $c = RlsContext::bypass('nightly-export');
        $this->assertTrue($c->isBypass());
        $this->assertSame('nightly-export', $c->reason());
        $this->assertSame([], $c->values());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/RlsContextTest.php`
Expected: FAIL — class `RlsContext` not found.

- [ ] **Step 3: Write the implementation**

`src/Context/RlsContext.php`:

```php
<?php

namespace Radiergummi\Rls\Context;

final class RlsContext
{
    private function __construct(
        private readonly array $values,
        private readonly bool $bypass = false,
        private readonly ?string $reason = null,
    ) {}

    public static function make(array $values): self
    {
        return new self($values);
    }

    public static function bypass(string $reason): self
    {
        return new self([], true, $reason);
    }

    public function values(): array
    {
        return $this->values;
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function with(array $values): self
    {
        return new self(array_merge($this->values, $values), $this->bypass, $this->reason);
    }

    public function isBypass(): bool
    {
        return $this->bypass;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/RlsContextTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Context/RlsContext.php tests/Unit/RlsContextTest.php
git commit -m "feat: immutable RlsContext value object"
```

---

### Task 3: RlsManager, facade, and container binding

**Files:**
- Create: `src/Context/RlsManager.php`, `src/Facades/Rls.php`
- Modify: `src/RlsServiceProvider.php`
- Test: `tests/Unit/RlsManagerTest.php`

**Interfaces:**
- Consumes: `RlsContext` (Task 2).
- Produces: `RlsManager` with `push(RlsContext): void`, `pop(): void`, `current(): ?RlsContext`, `hasContext(): bool`, `actingAs(array $context, ?Closure $callback = null): mixed`, `set(string $key, mixed $value): void`, `get(string $key): mixed`, `context(): array`, `withoutRls(string $reason, Closure $callback): mixed`, `system(string $reason, Closure $callback): mixed`, `forget(): void`, `setSyncCallback(?Closure): void`. Bound as singleton `rls`. Facade `Radiergummi\Rls\Facades\Rls`.
- Note: `push`/`pop`/`set`/`forget` invoke the optional sync callback (wired in Task 5) so an already-open transaction re-injects context. In this task the callback is null (no DB).

- [ ] **Step 1: Write the failing test**

`tests/Unit/RlsManagerTest.php`:

```php
<?php

namespace Radiergummi\Rls\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Radiergummi\Rls\Context\RlsContext;
use Radiergummi\Rls\Context\RlsManager;

class RlsManagerTest extends TestCase
{
    public function test_starts_empty(): void
    {
        $m = new RlsManager();
        $this->assertFalse($m->hasContext());
        $this->assertNull($m->current());
        $this->assertSame([], $m->context());
    }

    public function test_acting_as_scoped_pushes_and_pops(): void
    {
        $m = new RlsManager();
        $seen = null;
        $result = $m->actingAs(['tenant_id' => '9'], function () use ($m, &$seen) {
            $seen = $m->get('tenant_id');
            return 'ok';
        });
        $this->assertSame('9', $seen);
        $this->assertSame('ok', $result);
        $this->assertFalse($m->hasContext(), 'context popped after callback');
    }

    public function test_acting_as_pops_even_on_exception(): void
    {
        $m = new RlsManager();
        try {
            $m->actingAs(['tenant_id' => '9'], fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }
        $this->assertFalse($m->hasContext());
    }

    public function test_acting_as_imperative_persists(): void
    {
        $m = new RlsManager();
        $m->actingAs(['tenant_id' => '9']);
        $this->assertTrue($m->hasContext());
        $this->assertSame('9', $m->get('tenant_id'));
    }

    public function test_nested_contexts_stack(): void
    {
        $m = new RlsManager();
        $m->actingAs(['tenant_id' => 'outer']);
        $m->actingAs(['tenant_id' => 'inner'], function () use ($m) {
            $this->assertSame('inner', $m->get('tenant_id'));
        });
        $this->assertSame('outer', $m->get('tenant_id'));
    }

    public function test_without_rls_is_a_bypass_scope(): void
    {
        $m = new RlsManager();
        $m->withoutRls('seeding', function () use ($m) {
            $this->assertTrue($m->current()->isBypass());
            $this->assertSame('seeding', $m->current()->reason());
        });
        $this->assertFalse($m->hasContext());
    }

    public function test_set_merges_into_current(): void
    {
        $m = new RlsManager();
        $m->actingAs(['tenant_id' => '9']);
        $m->set('user_id', 5);
        $this->assertSame('9', $m->get('tenant_id'));
        $this->assertSame(5, $m->get('user_id'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/RlsManagerTest.php`
Expected: FAIL — class `RlsManager` not found.

- [ ] **Step 3: Write the implementation**

`src/Context/RlsManager.php`:

```php
<?php

namespace Radiergummi\Rls\Context;

use Closure;

class RlsManager
{
    /** @var list<RlsContext> */
    private array $stack = [];

    private ?Closure $sync = null;

    public function setSyncCallback(?Closure $sync): void
    {
        $this->sync = $sync;
    }

    public function push(RlsContext $context): void
    {
        $this->stack[] = $context;
        $this->afterChange();
    }

    public function pop(): void
    {
        array_pop($this->stack);
        $this->afterChange();
    }

    public function current(): ?RlsContext
    {
        return $this->stack === [] ? null : $this->stack[count($this->stack) - 1];
    }

    public function hasContext(): bool
    {
        return $this->stack !== [];
    }

    public function context(): array
    {
        return $this->current()?->values() ?? [];
    }

    public function get(string $key): mixed
    {
        return $this->current()?->get($key);
    }

    public function set(string $key, mixed $value): void
    {
        $current = $this->current();
        $this->pop();
        $this->push(($current ?? RlsContext::make([]))->with([$key => $value]));
    }

    public function actingAs(array $context, ?Closure $callback = null): mixed
    {
        return $this->run(RlsContext::make($context), $callback);
    }

    public function withoutRls(string $reason, Closure $callback): mixed
    {
        return $this->run(RlsContext::bypass($reason), $callback);
    }

    public function system(string $reason, Closure $callback): mixed
    {
        return $this->withoutRls($reason, $callback);
    }

    public function forget(): void
    {
        $this->stack = [];
        $this->afterChange();
    }

    private function run(RlsContext $context, ?Closure $callback): mixed
    {
        $this->push($context);

        if ($callback === null) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $this->pop();
        }
    }

    private function afterChange(): void
    {
        if ($this->sync !== null) {
            ($this->sync)();
        }
    }
}
```

- [ ] **Step 4: Write the facade**

`src/Facades/Rls.php`:

```php
<?php

namespace Radiergummi\Rls\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void push(\Radiergummi\Rls\Context\RlsContext $context)
 * @method static void pop()
 * @method static \Radiergummi\Rls\Context\RlsContext|null current()
 * @method static bool hasContext()
 * @method static array context()
 * @method static mixed get(string $key)
 * @method static void set(string $key, mixed $value)
 * @method static mixed actingAs(array $context, ?\Closure $callback = null)
 * @method static mixed withoutRls(string $reason, \Closure $callback)
 * @method static mixed system(string $reason, \Closure $callback)
 * @method static void forget()
 *
 * @see \Radiergummi\Rls\Context\RlsManager
 */
class Rls extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'rls';
    }
}
```

- [ ] **Step 5: Register the singleton in the provider**

Modify `src/RlsServiceProvider.php` `register()` to bind the singleton (append after `mergeConfigFrom`):

```php
        $this->app->singleton('rls', fn () => new \Radiergummi\Rls\Context\RlsManager());
        $this->app->alias('rls', \Radiergummi\Rls\Context\RlsManager::class);
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/RlsManagerTest.php`
Expected: PASS (7 tests).

- [ ] **Step 7: Commit**

```bash
git add src/Context/RlsManager.php src/Facades/Rls.php src/RlsServiceProvider.php tests/Unit/RlsManagerTest.php
git commit -m "feat: stack-based RlsManager with bypass scopes and facade"
```

---

### Task 4: SQL helper functions and installer

**Files:**
- Create: `src/Support/RlsFunctions.php`, `tests/database/migrations/0001_01_01_000000_install_rls_functions.php`
- Test: `tests/Feature/RlsFunctionsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `RlsFunctions::install(): void` (runs the CREATE statements on the default connection), `RlsFunctions::statements(): array<string>` (the raw SQL). Creates schema `rls` with `rls.context(text) returns text` and `rls.bypass() returns boolean`, both `STABLE`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/RlsFunctionsTest.php`:

```php
<?php

namespace Radiergummi\Rls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Radiergummi\Rls\Tests\TestCase;

class RlsFunctionsTest extends TestCase
{
    public function test_context_returns_null_when_unset(): void
    {
        $this->assertNull(DB::selectOne("select rls.context('tenant_id') as v")->v);
    }

    public function test_context_reads_transaction_local_guc(): void
    {
        DB::transaction(function () {
            DB::statement("select set_config('app.tenant_id', 'abc', true)");
            $this->assertSame('abc', DB::selectOne("select rls.context('tenant_id') as v")->v);
        });
    }

    public function test_context_treats_empty_string_as_null(): void
    {
        DB::transaction(function () {
            DB::statement("select set_config('app.tenant_id', '', true)");
            $this->assertNull(DB::selectOne("select rls.context('tenant_id') as v")->v);
        });
    }

    public function test_bypass_defaults_to_false(): void
    {
        $this->assertFalse(DB::selectOne('select rls.bypass() as v')->v);
    }

    public function test_bypass_reads_guc(): void
    {
        DB::transaction(function () {
            DB::statement("select set_config('app.bypass', 'on', true)");
            $this->assertTrue(DB::selectOne('select rls.bypass() as v')->v);
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/RlsFunctionsTest.php`
Expected: FAIL — schema/function `rls` does not exist (migration not present yet).

- [ ] **Step 3: Write the RlsFunctions support class**

`src/Support/RlsFunctions.php`:

```php
<?php

namespace Radiergummi\Rls\Support;

use Illuminate\Support\Facades\DB;

class RlsFunctions
{
    /** @return array<int, string> */
    public static function statements(): array
    {
        return [
            'create schema if not exists rls',

            <<<'SQL'
            create or replace function rls.context(key text)
            returns text
            language sql
            stable
            as $$
                select nullif(current_setting('app.' || key, true), '')
            $$
            SQL,

            <<<'SQL'
            create or replace function rls.bypass()
            returns boolean
            language sql
            stable
            as $$
                select coalesce(nullif(current_setting('app.bypass', true), ''), 'off')::boolean
            $$
            SQL,
        ];
    }

    public static function install(): void
    {
        foreach (self::statements() as $sql) {
            DB::statement($sql);
        }
    }
}
```

- [ ] **Step 4: Write the migration**

`tests/database/migrations/0001_01_01_000000_install_rls_functions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Radiergummi\Rls\Support\RlsFunctions;

return new class extends Migration
{
    public function up(): void
    {
        RlsFunctions::install();
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement('drop schema if exists rls cascade');
    }
};
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/RlsFunctionsTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Support/RlsFunctions.php tests/database/migrations/0001_01_01_000000_install_rls_functions.php tests/Feature/RlsFunctionsTest.php
git commit -m "feat: rls.context and rls.bypass SQL helper functions"
```

---

### Task 5: Connection integration (context injection + query wrapping)

**Files:**
- Create: `src/Database/HandlesRlsContext.php`, `src/Database/RlsPostgresConnection.php`
- Modify: `src/RlsServiceProvider.php`
- Test: `tests/Feature/ContextInjectionTest.php`

**Interfaces:**
- Consumes: `RlsManager` (`rls` singleton), `RlsContext`, config `rls.prefix`/`rls.boundary`.
- Produces: `RlsPostgresConnection extends PostgresConnection use HandlesRlsContext`. Trait overrides `beginTransaction()` (inject at level 1) and `run()` (wrap when boundary=`wrap`, context present, level 0). Public `applyRlsContext(): void` (idempotent; sets GUCs for current context, or clears to empty when none). Provider registers `Connection::resolverFor('pgsql', …)` and wires `RlsManager::setSyncCallback` to apply context to any open transaction on the default connection.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ContextInjectionTest.php`:

```php
<?php

namespace Radiergummi\Rls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Radiergummi\Rls\Facades\Rls;
use Radiergummi\Rls\Tests\TestCase;

class ContextInjectionTest extends TestCase
{
    public function test_context_reaches_db_within_refresh_database_transaction(): void
    {
        // RefreshDatabase already opened a transaction before this body ran.
        Rls::actingAs(['tenant_id' => 'abc']);
        $this->assertSame('abc', DB::selectOne("select rls.context('tenant_id') as v")->v);
    }

    public function test_scoped_context_applies_then_clears(): void
    {
        Rls::actingAs(['tenant_id' => 'xyz'], function () {
            $this->assertSame('xyz', DB::selectOne("select rls.context('tenant_id') as v")->v);
        });
        $this->assertNull(DB::selectOne("select rls.context('tenant_id') as v")->v);
    }

    public function test_bypass_scope_sets_bypass_guc(): void
    {
        Rls::withoutRls('seeding', function () {
            $this->assertTrue(DB::selectOne('select rls.bypass() as v')->v);
        });
        $this->assertFalse(DB::selectOne('select rls.bypass() as v')->v);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ContextInjectionTest.php`
Expected: FAIL — context not reaching DB (`v` is null), because no connection integration exists yet.

- [ ] **Step 3: Write the HandlesRlsContext trait**

`src/Database/HandlesRlsContext.php`:

```php
<?php

namespace Radiergummi\Rls\Database;

use Closure;

trait HandlesRlsContext
{
    public function beginTransaction(): void
    {
        parent::beginTransaction();

        if ($this->transactionLevel() === 1) {
            $this->applyRlsContext();
        }
    }

    protected function run($query, $bindings, Closure $callback)
    {
        if ($this->shouldWrapForRls()) {
            return $this->transaction(fn () => parent::run($query, $bindings, $callback));
        }

        return parent::run($query, $bindings, $callback);
    }

    protected function shouldWrapForRls(): bool
    {
        return $this->transactionLevel() === 0
            && config('rls.boundary', 'wrap') === 'wrap'
            && app('rls')->hasContext();
    }

    /**
     * Set transaction-local GUCs for the current context (idempotent).
     * When there is no active transaction, this is a no-op — context is
     * injected at the next beginTransaction() instead.
     */
    public function applyRlsContext(): void
    {
        if ($this->transactionLevel() === 0) {
            return;
        }

        $manager = app('rls');
        $prefix = config('rls.prefix', 'app.');
        $context = $manager->current();

        // Clear the dimensions we might have set, then set current ones.
        $this->setLocalConfig($prefix . 'bypass', $context?->isBypass() ? 'on' : '');

        if ($context !== null && ! $context->isBypass()) {
            foreach ($context->values() as $key => $value) {
                $this->setLocalConfig($prefix . $key, (string) $value);
            }
        }
    }

    private function setLocalConfig(string $name, string $value): void
    {
        parent::run(
            'select set_config(?, ?, true)',
            [$name, $value],
            fn ($query, $bindings) => $this->getPdo()->prepare($query)->execute($bindings),
        );
    }
}
```

- [ ] **Step 4: Write the RlsPostgresConnection**

`src/Database/RlsPostgresConnection.php`:

```php
<?php

namespace Radiergummi\Rls\Database;

use Illuminate\Database\PostgresConnection;

class RlsPostgresConnection extends PostgresConnection
{
    use HandlesRlsContext;
}
```

- [ ] **Step 5: Register the resolver and sync callback in the provider**

Modify `src/RlsServiceProvider.php`. Add imports at top:

```php
use Illuminate\Database\Connection;
use Radiergummi\Rls\Context\RlsManager;
use Radiergummi\Rls\Database\RlsPostgresConnection;
```

In `register()`, after binding the singleton, register the resolver:

```php
        Connection::resolverFor('pgsql', function ($pdo, $database, $prefix, $config) {
            $class = config('rls.connection_class', RlsPostgresConnection::class);

            return new $class($pdo, $database, $prefix, $config);
        });
```

In `boot()`, wire the sync callback so a context change re-injects into any open transaction on the default connection:

```php
        $manager = $this->app->make('rls');

        $manager->setSyncCallback(function () {
            $connection = $this->app->make('db')->connection();

            if ($connection instanceof RlsPostgresConnection && $connection->transactionLevel() > 0) {
                $connection->applyRlsContext();
            }
        });
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/ContextInjectionTest.php`
Expected: PASS (3 tests). This also proves the RefreshDatabase mid-transaction re-inject path works.

- [ ] **Step 7: Run the whole suite**

Run: `vendor/bin/phpunit`
Expected: PASS (all tasks so far green).

- [ ] **Step 8: Commit**

```bash
git add src/Database tests/Feature/ContextInjectionTest.php src/RlsServiceProvider.php
git commit -m "feat: inject transaction-local RLS context via custom connection"
```

---

### Task 6: Migration DSL (scopedBy + RLS macros)

**Files:**
- Create: `src/Schema/RlsSchemaMacros.php`
- Modify: `src/RlsServiceProvider.php`
- Test: `tests/Feature/PolicyDslTest.php`

**Interfaces:**
- Consumes: config `rls.role_model`/`rls.prefix`.
- Produces: Blueprint macros registered in `boot()`:
  - `$table->enableRowLevelSecurity(): void`
  - `$table->forceRowLevelSecurity(): void`
  - `$table->scopedBy(string $column, ?string $dimension = null, string $type = 'uuid'): void` — enables + forces (owner mode) RLS and creates a permissive base policy `<table>_access` plus a RESTRICTIVE isolation policy `<table>_<dimension>_isolation`. In owner mode the isolation predicates are `rls.bypass() OR <column> = rls.context('<dimension>')::<type>`.
- Note: macros push raw SQL onto the blueprint via `DB::statement` executed after table creation, using a post-create closure. Implementation registers them as `Blueprint` macros that call `$this->getConnection()`-independent `DB::statement`, scheduled through the blueprint's command list.

- [ ] **Step 1: Write the failing test**

`tests/Feature/PolicyDslTest.php`:

```php
<?php

namespace Radiergummi\Rls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Radiergummi\Rls\Tests\TestCase;

class PolicyDslTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->scopedBy('tenant_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('widgets');
        parent::tearDown();
    }

    public function test_rls_is_enabled_and_forced(): void
    {
        $row = DB::selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class where relname = ?',
            ['widgets'],
        );
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    public function test_creates_permissive_base_and_restrictive_isolation_policies(): void
    {
        $policies = collect(DB::select(
            'select policyname, permissive from pg_policies where tablename = ? order by policyname',
            ['widgets'],
        ))->keyBy('policyname');

        $this->assertTrue($policies->has('widgets_access'));
        $this->assertTrue($policies->has('widgets_tenant_id_isolation'));
        $this->assertSame('PERMISSIVE', $policies['widgets_access']->permissive);
        $this->assertSame('RESTRICTIVE', $policies['widgets_tenant_id_isolation']->permissive);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/PolicyDslTest.php`
Expected: FAIL — `Method Illuminate\Database\Schema\Blueprint::scopedBy does not exist`.

- [ ] **Step 3: Write the macros class**

`src/Schema/RlsSchemaMacros.php`:

```php
<?php

namespace Radiergummi\Rls\Schema;

use Illuminate\Database\Schema\Blueprint;

class RlsSchemaMacros
{
    public static function register(): void
    {
        Blueprint::macro('enableRowLevelSecurity', function (): void {
            /** @var Blueprint $this */
            $table = $this->getTable();
            $this->addCommand('rlsRaw', ['sql' => "alter table \"{$table}\" enable row level security"]);
        });

        Blueprint::macro('forceRowLevelSecurity', function (): void {
            /** @var Blueprint $this */
            $table = $this->getTable();
            $this->addCommand('rlsRaw', ['sql' => "alter table \"{$table}\" force row level security"]);
        });

        Blueprint::macro('scopedBy', function (string $column, ?string $dimension = null, string $type = 'uuid'): void {
            /** @var Blueprint $this */
            $table = $this->getTable();
            $dimension ??= $column;
            $owner = config('rls.role_model', 'owner') === 'owner';
            $predicate = sprintf('"%s" = rls.context(%s)::%s', $column, "'{$dimension}'", $type);

            if ($owner) {
                $predicate = "rls.bypass() or {$predicate}";
            }

            $this->addCommand('rlsRaw', ['sql' => "alter table \"{$table}\" enable row level security"]);

            if ($owner) {
                $this->addCommand('rlsRaw', ['sql' => "alter table \"{$table}\" force row level security"]);
            }

            $this->addCommand('rlsRaw', ['sql' =>
                "create policy \"{$table}_access\" on \"{$table}\" as permissive for all using (true) with check (true)"
            ]);

            $this->addCommand('rlsRaw', ['sql' =>
                "create policy \"{$table}_{$dimension}_isolation\" on \"{$table}\" " .
                "as restrictive for all using ({$predicate}) with check ({$predicate})"
            ]);
        });
    }
}
```

- [ ] **Step 4: Add the `rlsRaw` grammar compiler**

The macros queue a `rlsRaw` command; the Postgres schema grammar must know how to compile it. Add a small grammar macro in the same class's `register()` (append before the closing brace of `register()`):

```php
        \Illuminate\Database\Schema\Grammars\PostgresGrammar::macro('compileRlsRaw', function ($blueprint, $command) {
            return $command->sql;
        });
```

- [ ] **Step 5: Register macros in the provider boot()**

Modify `src/RlsServiceProvider.php` `boot()` — append:

```php
        \Radiergummi\Rls\Schema\RlsSchemaMacros::register();
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/PolicyDslTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Run the whole suite**

Run: `vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add src/Schema/RlsSchemaMacros.php src/RlsServiceProvider.php tests/Feature/PolicyDslTest.php
git commit -m "feat: scopedBy migration DSL emitting restrictive isolation policies"
```

---

### Task 7: Testing trait (helpers, assertions, leak canary)

**Files:**
- Create: `src/Testing/InteractsWithRls.php`
- Test: `tests/Feature/TestingHelpersTest.php`

**Interfaces:**
- Consumes: `Rls` facade, `Schema`, `DB`.
- Produces: trait `InteractsWithRls` (used by test classes) with: `withRlsContext(array $context, ?Closure $cb = null): mixed`, `actingAsTenant(string|int $id, ?Closure $cb = null): mixed` (maps to `['tenant_id' => $id]`), `withoutRls(string $reason, Closure $cb): mixed`, `assertTableProtected(string $table): void`, `assertRlsIsolates(string $model, $from, $cannotSee): void`, `assertCannotWriteAcrossTenants(string $model, $actingAs, $tenant): void`, and `assertRlsStackEmpty(): void` (leak canary, called in the trait's `tearDown`).
- Note: `assertRlsIsolates`/`assertCannotWriteAcrossTenants` take Eloquent model class strings and tenant ids; `from`/`cannotSee` are tenant ids present in the seeded data. This task tests them against the `widgets` table via an inline anonymous test using raw inserts (models arrive in Task 8), so keep assertions model-agnostic by accepting a table name too.

- [ ] **Step 1: Write the failing test**

`tests/Feature/TestingHelpersTest.php`:

```php
<?php

namespace Radiergummi\Rls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Radiergummi\Rls\Testing\InteractsWithRls;
use Radiergummi\Rls\Tests\TestCase;

class TestingHelpersTest extends TestCase
{
    use InteractsWithRls;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gadgets', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->scopedBy('tenant_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('gadgets');
        parent::tearDown();
    }

    public function test_assert_table_protected_passes(): void
    {
        $this->assertTableProtected('gadgets');
    }

    public function test_with_rls_context_scopes_reads(): void
    {
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();

        $this->withoutRls('seed', function () use ($a, $b) {
            DB::table('gadgets')->insert(['id' => Str::uuid(), 'tenant_id' => $a]);
            DB::table('gadgets')->insert(['id' => Str::uuid(), 'tenant_id' => $b]);
        });

        $this->withRlsContext(['tenant_id' => $a], function () {
            $this->assertSame(1, DB::table('gadgets')->count());
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/TestingHelpersTest.php`
Expected: FAIL — trait `InteractsWithRls` not found.

- [ ] **Step 3: Write the trait**

`src/Testing/InteractsWithRls.php`:

```php
<?php

namespace Radiergummi\Rls\Testing;

use Closure;
use Illuminate\Support\Facades\DB;
use Radiergummi\Rls\Facades\Rls;

trait InteractsWithRls
{
    protected function withRlsContext(array $context, ?Closure $callback = null): mixed
    {
        return Rls::actingAs($context, $callback);
    }

    protected function actingAsTenant(string|int $id, ?Closure $callback = null): mixed
    {
        return Rls::actingAs(['tenant_id' => $id], $callback);
    }

    protected function withoutRls(string $reason, Closure $callback): mixed
    {
        return Rls::withoutRls($reason, $callback);
    }

    protected function assertTableProtected(string $table): void
    {
        $row = DB::selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class where relname = ?',
            [$table],
        );

        $this->assertNotNull($row, "Table {$table} not found");
        $this->assertTrue((bool) $row->relrowsecurity, "RLS not enabled on {$table}");

        if (config('rls.role_model', 'owner') === 'owner') {
            $this->assertTrue((bool) $row->relforcerowsecurity, "RLS not forced on {$table}");
        }

        $hasRestrictive = collect(DB::select(
            'select permissive from pg_policies where tablename = ?',
            [$table],
        ))->contains(fn ($p) => $p->permissive === 'RESTRICTIVE');

        $this->assertTrue($hasRestrictive, "No restrictive isolation policy on {$table}");
    }

    protected function assertRlsIsolates(string $modelClass, mixed $from, mixed $cannotSee): void
    {
        $fromId = $this->tenantKey($from);
        $otherId = $this->tenantKey($cannotSee);

        Rls::actingAs(['tenant_id' => $fromId], function () use ($modelClass, $otherId) {
            $leaked = $modelClass::query()->where('tenant_id', $otherId)->count();
            $this->assertSame(0, $leaked, "Rows from tenant {$otherId} are visible to tenant");
        });
    }

    protected function assertCannotWriteAcrossTenants(string $modelClass, mixed $actingAs, mixed $tenant): void
    {
        $actingId = $this->tenantKey($actingAs);
        $foreignId = $this->tenantKey($tenant);

        Rls::actingAs(['tenant_id' => $actingId], function () use ($modelClass, $foreignId) {
            try {
                $modelClass::query()->create(['tenant_id' => $foreignId]);
                $this->fail('Expected WITH CHECK to reject cross-tenant write');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsStringIgnoringCase('row-level security', $e->getMessage());
            }
        });
    }

    protected function assertRlsStackEmpty(): void
    {
        $this->assertFalse(Rls::hasContext(), 'RLS context leaked past the test (stack not empty)');
    }

    private function tenantKey(mixed $value): mixed
    {
        if (is_object($value) && method_exists($value, 'getKey')) {
            return $value->getKey();
        }

        return $value;
    }

    protected function tearDown(): void
    {
        $leaked = Rls::hasContext();
        Rls::forget();
        parent::tearDown();
        $this->assertFalse($leaked, 'RLS context leaked past the test (stack not empty)');
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/TestingHelpersTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Testing/InteractsWithRls.php tests/Feature/TestingHelpersTest.php
git commit -m "feat: InteractsWithRls test helpers, assertions, and leak canary"
```

---

### Task 8: End-to-end tenant isolation feature test

**Files:**
- Create: `tests/Models/Tenant.php`, `tests/Models/Document.php`, `tests/database/factories/TenantFactory.php`, `tests/database/factories/DocumentFactory.php`, `tests/database/migrations/0001_01_01_000001_create_tenants_table.php`, `tests/database/migrations/0001_01_01_000002_create_documents_table.php`
- Test: `tests/Feature/TenantIsolationTest.php`

**Interfaces:**
- Consumes: everything above (`Rls`, `scopedBy`, `InteractsWithRls`).
- Produces: proof that reads are scoped, writes are confined (WITH CHECK), bypass sees all, no-context is fail-closed (zero rows), and a RESTRICTIVE isolation policy prevents an added permissive feature policy from leaking across tenants.

- [ ] **Step 1: Write the tenants and documents migrations**

`tests/database/migrations/0001_01_01_000001_create_tenants_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
```

`tests/database/migrations/0001_01_01_000002_create_documents_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('title');
            $table->timestamps();

            $table->scopedBy('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
```

- [ ] **Step 2: Write the models**

`tests/Models/Tenant.php`:

```php
<?php

namespace Radiergummi\Rls\Tests\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Radiergummi\Rls\Tests\Database\Factories\TenantFactory;

class Tenant extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
```

`tests/Models/Document.php`:

```php
<?php

namespace Radiergummi\Rls\Tests\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Radiergummi\Rls\Tests\Database\Factories\DocumentFactory;

class Document extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }
}
```

- [ ] **Step 3: Write the factories**

`tests/database/factories/TenantFactory.php`:

```php
<?php

namespace Radiergummi\Rls\Tests\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Radiergummi\Rls\Tests\Models\Tenant;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return ['name' => $this->faker->company()];
    }
}
```

`tests/database/factories/DocumentFactory.php`:

```php
<?php

namespace Radiergummi\Rls\Tests\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Radiergummi\Rls\Tests\Models\Document;

class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return ['title' => $this->faker->sentence()];
    }
}
```

- [ ] **Step 4: Write the end-to-end test**

`tests/Feature/TenantIsolationTest.php`:

```php
<?php

namespace Radiergummi\Rls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Radiergummi\Rls\Facades\Rls;
use Radiergummi\Rls\Testing\InteractsWithRls;
use Radiergummi\Rls\Tests\Models\Document;
use Radiergummi\Rls\Tests\Models\Tenant;
use Radiergummi\Rls\Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use InteractsWithRls;

    private Tenant $a;
    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutRls('seed', function () {
            $this->a = Tenant::factory()->create();
            $this->b = Tenant::factory()->create();
            Document::factory()->count(2)->create(['tenant_id' => $this->a->id]);
            Document::factory()->count(3)->create(['tenant_id' => $this->b->id]);
        });
    }

    public function test_table_is_protected(): void
    {
        $this->assertTableProtected('documents');
    }

    public function test_reads_are_scoped_to_the_acting_tenant(): void
    {
        $this->withRlsContext(['tenant_id' => $this->a->id], function () {
            $this->assertSame(2, Document::count());
        });

        $this->withRlsContext(['tenant_id' => $this->b->id], function () {
            $this->assertSame(3, Document::count());
        });
    }

    public function test_isolation_helper_confirms_no_leak(): void
    {
        $this->assertRlsIsolates(Document::class, from: $this->a, cannotSee: $this->b);
    }

    public function test_cross_tenant_writes_are_rejected(): void
    {
        $this->assertCannotWriteAcrossTenants(Document::class, actingAs: $this->a, tenant: $this->b->id);
    }

    public function test_missing_context_is_fail_closed(): void
    {
        // No context set: DB returns zero rows rather than leaking.
        $this->assertSame(0, Document::count());
    }

    public function test_bypass_sees_all_tenants(): void
    {
        Rls::withoutRls('audit', function () {
            $this->assertSame(5, Document::count());
        });
    }

    public function test_restrictive_policy_prevents_permissive_feature_leak(): void
    {
        // Add a permissive feature policy that would, under a permissive-only
        // design, OR-in other tenants' rows. The RESTRICTIVE isolation policy
        // must still AND-confine to the acting tenant.
        DB::statement('create policy documents_public on documents as permissive for select using (true)');

        try {
            $this->withRlsContext(['tenant_id' => $this->a->id], function () {
                $this->assertSame(2, Document::count(), 'Restrictive policy failed to confine reads');
            });
        } finally {
            DB::statement('drop policy documents_public on documents');
        }
    }
}
```

- [ ] **Step 5: Run the end-to-end test**

Run: `vendor/bin/phpunit tests/Feature/TenantIsolationTest.php`
Expected: PASS (7 tests). This is the PoC's core proof.

- [ ] **Step 6: Run the entire suite**

Run: `vendor/bin/phpunit`
Expected: ALL green (Unit + Feature).

- [ ] **Step 7: Commit**

```bash
git add tests/Models tests/database/factories tests/database/migrations/0001_01_01_000001_create_tenants_table.php tests/database/migrations/0001_01_01_000002_create_documents_table.php tests/Feature/TenantIsolationTest.php
git commit -m "test: end-to-end tenant isolation proof (reads, writes, bypass, fail-closed, restrictive)"
```

---

## Teardown (after PoC review)

```bash
docker rm -f rls-pg    # remove the throwaway Postgres container when done
```

---

## Self-Review Notes

- **Spec coverage (subset intentional):** PoC implements design §4 (context, minus Laravel Context backing — in-memory stack), §5 (transaction unit, set_config bound-param, wrap boundary), §6 (owner-mode connection injection + RefreshDatabase re-inject), §7 (owner mode + FORCE + owner-mode bypass clause), §8 (rls.context/rls.bypass, STABLE), §9 (scopedBy, RESTRICTIVE + permissive base, USING+WITH CHECK), §13 (test helpers, assertions, leak canary), §17 (fail-closed proof). Deliberately deferred (documented in Global Constraints): restricted mode, extension install, session strategy, boundary=explicit/request, per-table fail-loud guard, queue/Octane/HTTP, declared-schema typed helpers, `rls:*` commands, tpetry interop. These are post-PoC.
- **Placeholder scan:** none — every step has concrete code/commands.
- **Type consistency:** `RlsContext` (make/bypass/values/get/has/with/isBypass/reason), `RlsManager` (push/pop/current/hasContext/context/get/set/actingAs/withoutRls/system/forget/setSyncCallback), `RlsPostgresConnection::applyRlsContext`, Blueprint `scopedBy`/`enableRowLevelSecurity`/`forceRowLevelSecurity`, trait `InteractsWithRls` — names match across tasks.
- **Known risk to watch during execution:** the `rlsRaw` blueprint command + `compileRlsRaw` grammar macro is the least-certain mechanism (Laravel resolves `compile<Command>` by convention). If the grammar macro isn't picked up, fallback is to run the ALTER/CREATE POLICY statements via a `DB::statement` inside an `after`-create hook registered on the blueprint, or via `$table->getConnection()`. Validate at Task 6 Step 6.
