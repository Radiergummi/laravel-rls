# Endpoint-level Performance Cells Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:
> executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add endpoint-level (K-standalone-query) performance measurement to the bench harness — six boundary/strategy
configs × K, plus a Toxiproxy latency sweep — proving RLS's `wrap` overhead is round-trip-bound while the `request`
boundary and `session` strategy flatten it.

**Architecture:** Extends the merged v1 per-query harness. Everything is dev-only under `bench/` (autoloaded via
`bench/` PSR-4) and `tests/`; **no `src/` changes**. New bench units (`EndpointConfig`, `Endpoint`, `Toxiproxy`) plus
modifications to `Boot`, `BenchmarkEnvironment`, both reporters, and `run.php`. `run.php` sets `database.default`/
`rls.strategy` per config so the existing sync-callback + connection-injection machinery applies with no special-casing;
each cell runs inside one `try/finally` that resets session context (while the strategy is still `session`) before
restoring config.

**Tech Stack:** PHP 8.1+, Laravel/Orchestra Testbench, PostgreSQL, PgBouncer (transaction pooling), Toxiproxy (latency
injection via Docker), PHPUnit, PHPStan (level 8), Pint.

## Global Constraints

- **No `src/` changes.** Only `bench/`, `tests/`, and `tests/bin/` are touched. (spec: "no `src/` changes", "dev-only")
- **`declare(strict_types=1);`** at the top of every new PHP file.
- **Value objects are `final readonly class`.** (spec: `EndpointConfig`)
- **PHPStan level 8 applies to `src` + `tests` only** (`phpstan.neon` paths). `bench/` is **not** analysed — but every
  new/edited **test** file must be level-8 clean. In tests, resolve services through typed facades (`DB::connection()`,
  `Rls::forget()`) or `make(RlsManager::class)`, never `$app->make('db')->…` (returns `mixed`); narrow to
  `RlsPostgresConnection` with `instanceof` before calling `resetSessionContext()`.
- **Fixed endpoint scale = 100k; K ∈ {1, 10, 30}.** (spec: "What an endpoint is")
- **Config 6 (`pgbouncer·session`) is unsafe by construction** — flagged, never measured single-client. (spec C2)
- **`pgsql_pgbouncer` connection mirrors `PgBouncerTest` exactly:** port 6432, `sslmode => 'disable'`,
  `PDO::ATTR_EMULATE_PREPARES => true`.
- **The 200 endpoint-iteration default is load-bearing** — do not lower it for the committed baseline.
- **Gated cells never fail the run.** If PgBouncer (`:6432`) or Toxiproxy (admin + data-path probe) is unavailable, the
  dependent cells are omitted and the availability is recorded in `BenchmarkEnvironment`.
- **Commands:** tests `composer test`; lint `composer lint`; format `composer format`; bench `composer bench`.

**Two refinements this plan makes over the spec's wording (both faithful to its intent):**

1. The six configs live in a static factory `EndpointConfig::matrix(): list<self>` (spec said "built in `run.php`") so
   `run.php` and `EndpointTest` share one definition (DRY).
2. `EndpointConfig` carries an explicit `bool $unsafe` field — the literal encoding of "unsafe by construction" (spec
   C2), asserted directly instead of via a runtime guard.

---

## File Structure

**Create:**

- `bench/EndpointConfig.php` — value object + `matrix()` factory (the six configs).
- `bench/Endpoint.php` — the timed K-query operation + `treatmentIsCorrect()` sanity check.
- `bench/Toxiproxy.php` — admin-API client + pure `payload()`.
- `tests/bin/setup-toxiproxy.sh` — Docker one-liner for the latency sweep.
- `tests/Unit/Bench/EndpointConfigTest.php`
- `tests/Unit/Bench/ToxiproxyTest.php`
- `tests/Unit/Bench/MarkdownReporterTest.php`
- `tests/Feature/Bench/EndpointTest.php`

**Modify:**

- `bench/Boot.php` — add `pgsql_pgbouncer` + `pgsql_delayed` connections.
- `bench/BenchmarkEnvironment.php` — add `toxiproxy: bool`.
- `bench/Report/JsonReporter.php` — add `endpoints` + `latency_sweep` keys.
- `bench/Report/MarkdownReporter.php` — endpoints table, sweep table, endpoint headline.
- `bench/run.php` — endpoints phase + latency sweep + CLI opts + availability probes.
- `tests/Feature/Bench/BootTest.php` — assert the two new connections resolve.
- `tests/Unit/Bench/JsonReporterTest.php` — updated `describe()` + `render()` call sites.
- `tests/Feature/Bench/BenchSmokeTest.php` — assert `endpoints` + env keys.

---

## Task 1: Boot — register the endpoint connections

**Files:**

- Modify: `bench/Boot.php`
- Test: `tests/Feature/Bench/BootTest.php`

**Interfaces:**

- Consumes: `Boot::app(): Application`; the `RlsPostgresConnection` resolver (registered for driver `pgsql`).
- Produces: two configured connections resolvable by name — `pgsql_pgbouncer` (port 6432) and `pgsql_delayed` (port
  5433), both driver `pgsql`, both `RlsPostgresConnection`.

- [ ] **Step 1: Write the failing test** — add to `tests/Feature/Bench/BootTest.php` (add
  `use Illuminate\Support\Facades\DB;` to the imports):

```php
    #[Test]
    #[TestDox('Boot::app() registers the pgbouncer and delayed RLS connections')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function registers_endpoint_connections(): void
    {
        Boot::app();

        // Resolving a connection builds it lazily (no socket opened until a query), so these
        // assertions hold even when :6432 / :5433 are down.
        $this->assertInstanceOf(RlsPostgresConnection::class, DB::connection('pgsql_pgbouncer'));
        $this->assertInstanceOf(RlsPostgresConnection::class, DB::connection('pgsql_delayed'));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter registers_endpoint_connections`
Expected: FAIL — `InvalidArgumentException: Database connection [pgsql_pgbouncer] not configured.`

- [ ] **Step 3: Implement the connections** — in `bench/Boot.php`, add `use PDO;` to the imports, then inside
  `defineEnvironment()` after the existing `pgsql_admin` line, append:

```php
        $app['config']->set('database.connections.pgsql_pgbouncer', [
            ...$connection('rls_app'),
            'port' => 6432,
            // Mirror PgBouncerTest exactly: transaction pooling can't carry server-side prepared
            // statements, and the local pooler listener offers no TLS.
            'sslmode' => 'disable',
            'options' => [PDO::ATTR_EMULATE_PREPARES => true],
        ]);
        $app['config']->set('database.connections.pgsql_delayed', [
            ...$connection('rls_app'),
            'port' => 5433, // Toxiproxy proxy listen port
            'sslmode' => 'disable',
            'options' => [PDO::ATTR_EMULATE_PREPARES => true],
        ]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter registers_endpoint_connections`
Expected: PASS

- [ ] **Step 5: Lint, format, commit**

```bash
composer format && composer lint
git add bench/Boot.php tests/Feature/Bench/BootTest.php
git commit -m "feat(bench): register pgsql_pgbouncer and pgsql_delayed connections"
```

---

## Task 2: EndpointConfig value object + matrix

**Files:**

- Create: `bench/EndpointConfig.php`
- Test: `tests/Unit/Bench/EndpointConfigTest.php`

**Interfaces:**

- Produces:
    - `final readonly class EndpointConfig` with public promoted props
      `string $label, string $connectionName, string $strategy, bool $oneTransaction, string $boundaryLabel, bool $unsafe = false`.
    - `EndpointConfig::matrix(): list<self>` — the six configs, matrix order (indices 0–2 = direct wrap/request/session;
      3–4 = pgbouncer wrap/request; 5 = pgbouncer session, `unsafe = true`).

- [ ] **Step 1: Write the failing test** — create `tests/Unit/Bench/EndpointConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\EndpointConfig;

#[TestDox('Bench EndpointConfig')]
class EndpointConfigTest extends TestCase
{
    #[Test]
    #[TestDox('matrix() yields six configs and flags pgbouncer-session unsafe by construction')]
    public function matrix_flags_the_unsafe_config(): void
    {
        $matrix = EndpointConfig::matrix();

        $this->assertCount(6, $matrix);

        // Direct configs are safe and measured.
        $this->assertFalse($matrix[0]->unsafe);
        $this->assertSame('direct·transaction·wrap', $matrix[0]->label);
        $this->assertFalse($matrix[0]->oneTransaction);
        $this->assertTrue($matrix[1]->oneTransaction); // request boundary = one txn

        // Config 6 is unsafe by construction.
        $this->assertTrue($matrix[5]->unsafe);
        $this->assertSame('session', $matrix[5]->strategy);
        $this->assertSame('pgsql_pgbouncer', $matrix[5]->connectionName);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter matrix_flags_the_unsafe_config`
Expected: FAIL — `Class "Radiergummi\LaravelRls\Bench\EndpointConfig" not found`.

- [ ] **Step 3: Implement `EndpointConfig`** — create `bench/EndpointConfig.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

/**
 * One endpoint measurement config: a connection path crossed with a strategy/boundary.
 *
 * `$unsafe` encodes the pgbouncer·session incompatibility "by construction" — a session GUC set
 * outside a transaction does not survive PgBouncer transaction pooling, and no single-client guard
 * can observe it (the reused backend returns correct rows). It is flagged, never measured.
 */
final readonly class EndpointConfig
{
    public function __construct(
        public string $label,
        public string $connectionName,
        public string $strategy,      // 'transaction' | 'session'
        public bool $oneTransaction,
        public string $boundaryLabel, // 'wrap' | 'request' | '—'
        public bool $unsafe = false,
    ) {}

    /**
     * The six connection-path × strategy/boundary configs, in matrix order.
     *
     * @return list<self>
     */
    public static function matrix(): array
    {
        return [
            new self('direct·transaction·wrap', 'pgsql', 'transaction', false, 'wrap'),
            new self('direct·transaction·request', 'pgsql', 'transaction', true, 'request'),
            new self('direct·session', 'pgsql', 'session', false, '—'),
            new self('pgbouncer·transaction·wrap', 'pgsql_pgbouncer', 'transaction', false, 'wrap'),
            new self('pgbouncer·transaction·request', 'pgsql_pgbouncer', 'transaction', true, 'request'),
            new self('pgbouncer·session', 'pgsql_pgbouncer', 'session', false, '—', true),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter matrix_flags_the_unsafe_config`
Expected: PASS

- [ ] **Step 5: Lint, format, commit**

```bash
composer format && composer lint
git add bench/EndpointConfig.php tests/Unit/Bench/EndpointConfigTest.php
git commit -m "feat(bench): EndpointConfig value object + six-config matrix"
```

---

## Task 3: Toxiproxy admin-API client

**Files:**

- Create: `bench/Toxiproxy.php`
- Test: `tests/Unit/Bench/ToxiproxyTest.php`

**Interfaces:**

- Produces `final class Toxiproxy`:
    - `__construct(string $admin = 'http://127.0.0.1:8474')`
    - `available(): bool` — GET `/version`.
    - `reset(string $name, string $listen, string $upstream): void` — delete-then-create the proxy.
    - `setLatency(string $name, int $ms, int $jitterMs): void` — replace the downstream latency toxic.
    - `clear(string $name): void` — remove the latency toxic.
    -
    `payload(int $ms, int $jitterMs): array{name:string,type:string,stream:string,attributes:array{latency:int,jitter:int}}` —
    pure.

- [ ] **Step 1: Write the failing test** — create `tests/Unit/Bench/ToxiproxyTest.php` (pure `payload()`, no network):

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Toxiproxy;

#[TestDox('Bench Toxiproxy')]
class ToxiproxyTest extends TestCase
{
    #[Test]
    #[TestDox('payload() builds a downstream latency toxic')]
    public function payload_builds_a_latency_toxic(): void
    {
        $payload = (new Toxiproxy())->payload(5, 1);

        $this->assertSame('latency_downstream', $payload['name']);
        $this->assertSame('latency', $payload['type']);
        $this->assertSame('downstream', $payload['stream']);
        $this->assertSame(5, $payload['attributes']['latency']);
        $this->assertSame(1, $payload['attributes']['jitter']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter payload_builds_a_latency_toxic`
Expected: FAIL — `Class "Radiergummi\LaravelRls\Bench\Toxiproxy" not found`.

- [ ] **Step 3: Implement `Toxiproxy`** — create `bench/Toxiproxy.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Minimal Toxiproxy admin-API client for the latency sweep. Network calls are exercised only when
 * the proxy is up; payload() is pure and unit-tested.
 */
final class Toxiproxy
{
    private const TOXIC = 'latency_downstream';

    public function __construct(private readonly string $admin = 'http://127.0.0.1:8474') {}

    public function available(): bool
    {
        try {
            return Http::timeout(2)->get("{$this->admin}/version")->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function reset(string $name, string $listen, string $upstream): void
    {
        Http::delete("{$this->admin}/proxies/{$name}");
        Http::post("{$this->admin}/proxies", [
            'name' => $name,
            'listen' => $listen,
            'upstream' => $upstream,
            'enabled' => true,
        ]);
    }

    public function setLatency(string $name, int $ms, int $jitterMs): void
    {
        // Idempotent: drop any existing latency toxic, then (re)create it with the new attributes.
        Http::delete("{$this->admin}/proxies/{$name}/toxics/" . self::TOXIC);
        Http::post("{$this->admin}/proxies/{$name}/toxics", $this->payload($ms, $jitterMs));
    }

    public function clear(string $name): void
    {
        Http::delete("{$this->admin}/proxies/{$name}/toxics/" . self::TOXIC);
    }

    /**
     * The downstream latency toxic body.
     *
     * @return array{name:string,type:string,stream:string,attributes:array{latency:int,jitter:int}}
     */
    public function payload(int $ms, int $jitterMs): array
    {
        return [
            'name' => self::TOXIC,
            'type' => 'latency',
            'stream' => 'downstream',
            'attributes' => ['latency' => $ms, 'jitter' => $jitterMs],
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter payload_builds_a_latency_toxic`
Expected: PASS

- [ ] **Step 5: Lint, format, commit**

```bash
composer format && composer lint
git add bench/Toxiproxy.php tests/Unit/Bench/ToxiproxyTest.php
git commit -m "feat(bench): Toxiproxy admin-API client with pure payload()"
```

---

## Task 4: BenchmarkEnvironment — toxiproxy availability flag

**Files:**

- Modify: `bench/BenchmarkEnvironment.php`
- Modify: `bench/run.php` (keep the existing `describe()` call compiling — placeholder value)
- Test: `tests/Unit/Bench/JsonReporterTest.php` (two `describe()` call sites)

**Interfaces:**

- Consumes:
  `describe(string $pgVersion, string $gitCommit, string $generatedAt, bool $pgbouncer, bool $emulatePrepares)` (current
  signature).
- Produces:
  `describe(string $pgVersion, string $gitCommit, string $generatedAt, bool $pgbouncer, bool $toxiproxy, bool $emulatePrepares): array{…, pgbouncer: bool, toxiproxy: bool, …}` —
  `toxiproxy` inserted **after** `pgbouncer` (the two availability flags grouped), returned array gains a `toxiproxy`
  key.

- [ ] **Step 1: Update the failing test** — in `tests/Unit/Bench/JsonReporterTest.php`, both
  `BenchmarkEnvironment::describe(...)` calls pass positional `false, false` for `(pgbouncer, emulatePrepares)`. Insert
  a third `false` (the new `toxiproxy`) so each reads `..., '2026-07-06T00:00:00Z', false, false, false`. Then add a
  `toxiproxy` assertion to `renders_document()` after the `git_commit` assertion:

```php
        $this->assertArrayHasKey('toxiproxy', $document['env']);
        $this->assertFalse($document['env']['toxiproxy']);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter "Bench JsonReporter"`
Expected: FAIL — too many arguments to `describe()` (still 5-param) / missing `toxiproxy` key.

- [ ] **Step 3: Implement the signature change** — in `bench/BenchmarkEnvironment.php`, update the `@return` shape to
  add `toxiproxy: bool` after `pgbouncer: bool`, add the `bool $toxiproxy` parameter after `$pgbouncer`, and add the
  key:

```php
    /**
     * @return array{
     *     pg_version: string,
     *     php_version: string,
     *     uname: string,
     *     emulate_prepares: bool,
     *     pgbouncer: bool,
     *     toxiproxy: bool,
     *     git_commit: string,
     *     generated_at: string
     * }
     */
    public static function describe(
        string $pgVersion,
        string $gitCommit,
        string $generatedAt,
        bool $pgbouncer,
        bool $toxiproxy,
        bool $emulatePrepares,
    ): array {
        return [
            'pg_version' => $pgVersion,
            'php_version' => PHP_VERSION,
            'uname' => php_uname(),
            'emulate_prepares' => $emulatePrepares,
            'pgbouncer' => $pgbouncer,
            'toxiproxy' => $toxiproxy,
            'git_commit' => $gitCommit,
            'generated_at' => $generatedAt,
        ];
    }
```

- [ ] **Step 4: Keep run.php compiling** — in `bench/run.php`, update the `describe(...)` call to add a named
  `toxiproxy:` placeholder (Task 9 replaces `false` with the real probe):

```php
    pgbouncer: false,
    toxiproxy: false,
    emulatePrepares: (bool) $db->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES),
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter "Bench JsonReporter" && vendor/bin/phpunit --filter "Bench smoke"`
Expected: PASS (both) — smoke still runs `run.php` end-to-end.

- [ ] **Step 6: Lint, format, commit**

```bash
composer format && composer lint
git add bench/BenchmarkEnvironment.php bench/run.php tests/Unit/Bench/JsonReporterTest.php
git commit -m "feat(bench): add toxiproxy availability flag to BenchmarkEnvironment"
```

---

## Task 5: Endpoint — the timed K-query operation

**Files:**

- Create: `bench/Endpoint.php`
- Test: `tests/Feature/Bench/EndpointTest.php`

**Interfaces:**

- Consumes: `Boot::app()`, `Schema::seed(string): TableSet`, `TableSet` (`probeRowId`, `probeTenantId`),
  `EndpointConfig`, `TableSet::CONTROL`/`::TREATMENT`, `RlsManager::isolateTo()`, the default connection resolved via
  `$app->make('db')->connection()`.
- Produces `final class Endpoint`:
    - `__construct(Application $app, TableSet $tables, int $k)`
    - `run(EndpointConfig $cfg, string $variant): void` — one timed op. `control`: K plain selects on `bench_control`
      with explicit `WHERE id = ? AND tenant_id = ?`. `treatment`: establish context once via `isolateTo`, run K scoped
      `bench_treatment` selects, wrapped in one `$conn->transaction(...)` iff `$cfg->oneTransaction`.
    - `treatmentIsCorrect(EndpointConfig $cfg): bool` — sanity check (outside the timed path): scoped `bench_treatment`
      count under context equals the non-RLS `bench_control` count for the probe tenant. **Not** used to classify config
      6.

- [ ] **Step 1: Write the failing test** — create `tests/Feature/Bench/EndpointTest.php`. Direct configs (1–3) always
  run at localhost; PgBouncer configs (4–5) are gated on `:6432`; config 6 is asserted unsafe by construction (no
  guard):

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature\Bench;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Boot;
use Radiergummi\LaravelRls\Bench\Endpoint;
use Radiergummi\LaravelRls\Bench\EndpointConfig;
use Radiergummi\LaravelRls\Bench\Schema;
use Radiergummi\LaravelRls\Context\RlsManager;
use Radiergummi\LaravelRls\Database\RlsPostgresConnection;
use Throwable;

#[TestDox('Bench Endpoint')]
class EndpointTest extends TestCase
{
    #[Test]
    #[TestDox('Direct configs scope correctly and run without error')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function direct_configs_scope_correctly_and_run(): void
    {
        $app = Boot::app();
        $schema = new Schema($app);
        $tables = $schema->seed('1k'); // scale-independent correctness; fast

        try {
            foreach (array_slice(EndpointConfig::matrix(), 0, 3) as $cfg) {
                config(['database.default' => $cfg->connectionName, 'rls.strategy' => $cfg->strategy]);
                $endpoint = new Endpoint($app, $tables, 5);

                try {
                    $this->assertTrue($endpoint->treatmentIsCorrect($cfg), $cfg->label);
                    $endpoint->run($cfg, 'control');
                    $endpoint->run($cfg, 'treatment');
                } finally {
                    $app->make(RlsManager::class)->forget();
                    $connection = DB::connection($cfg->connectionName);
                    if ($connection instanceof RlsPostgresConnection) {
                        $connection->resetSessionContext(); // while strategy is still 'session'
                    }
                    config(['database.default' => 'pgsql', 'rls.strategy' => 'transaction']);
                }
            }
        } finally {
            $schema->drop();
        }
    }

    #[Test]
    #[TestDox('pgbouncer-session is unsafe by construction')]
    public function pgbouncer_session_is_unsafe_by_construction(): void
    {
        $config = EndpointConfig::matrix()[5];

        $this->assertTrue($config->unsafe);
        $this->assertSame('session', $config->strategy);
        $this->assertSame('pgsql_pgbouncer', $config->connectionName);
    }

    #[Test]
    #[TestDox('PgBouncer transaction configs scope correctly when reachable')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function pgbouncer_transaction_configs_scope_correctly(): void
    {
        $app = Boot::app();

        try {
            DB::connection('pgsql_pgbouncer')->getPdo();
        } catch (Throwable $exception) {
            $this->markTestSkipped("PgBouncer not reachable on 127.0.0.1:6432: {$exception->getMessage()}");
        }

        $schema = new Schema($app);
        $tables = $schema->seed('1k');

        try {
            foreach (array_slice(EndpointConfig::matrix(), 3, 2) as $cfg) { // configs 4, 5
                config(['database.default' => $cfg->connectionName, 'rls.strategy' => $cfg->strategy]);
                $endpoint = new Endpoint($app, $tables, 5);

                try {
                    $this->assertTrue($endpoint->treatmentIsCorrect($cfg), $cfg->label);
                    $endpoint->run($cfg, 'treatment');
                } finally {
                    $app->make(RlsManager::class)->forget();
                    config(['database.default' => 'pgsql', 'rls.strategy' => 'transaction']);
                }
            }
        } finally {
            $schema->drop();
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter "Bench Endpoint"`
Expected: FAIL — `Class "Radiergummi\LaravelRls\Bench\Endpoint" not found`.

- [ ] **Step 3: Implement `Endpoint`** — create `bench/Endpoint.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Radiergummi\LaravelRls\Context\RlsManager;

/**
 * One endpoint = establish RLS context once, then run K standalone queries (no enclosing explicit
 * transaction, unless the config models the `request` boundary). run() is the single timed op;
 * run.php sets database.default/rls.strategy around it so the config's connection + strategy apply.
 */
final class Endpoint
{
    public function __construct(
        private readonly Application $app,
        private readonly TableSet $tables,
        private readonly int $k,
    ) {}

    public function run(EndpointConfig $cfg, string $variant): void
    {
        if ($variant === 'control') {
            for ($i = 0; $i < $this->k; $i++) {
                $this->db()->select(
                    'select * from ' . TableSet::CONTROL . ' where id = ? and tenant_id = ?',
                    [$this->tables->probeRowId, $this->tables->probeTenantId],
                );
            }

            return;
        }

        $this->rls()->isolateTo(
            ['tenant_id' => $this->tables->probeTenantId],
            function () use ($cfg): void {
                $selects = function (): void {
                    for ($i = 0; $i < $this->k; $i++) {
                        $this->db()->select(
                            'select * from ' . TableSet::TREATMENT . ' where id = ?',
                            [$this->tables->probeRowId],
                        );
                    }
                };

                // request boundary: one transaction wraps all K selects (context injected once at
                // BEGIN). Otherwise each standalone select auto-wraps (wrap) or runs on the session
                // GUC (session).
                if ($cfg->oneTransaction) {
                    $this->db()->transaction($selects);
                } else {
                    $selects();
                }
            },
        );
    }

    public function treatmentIsCorrect(EndpointConfig $cfg): bool
    {
        // Expected: the probe tenant's true row count, read straight from the non-RLS control table.
        $expected = (int) $this->db()->selectOne(
            'select count(*) as c from ' . TableSet::CONTROL . ' where tenant_id = ?',
            [$this->tables->probeTenantId],
        )?->c;

        // Actual: the RLS-scoped count of the treatment table under the probe context.
        $actual = (int) $this->rls()->isolateTo(
            ['tenant_id' => $this->tables->probeTenantId],
            fn(): mixed => $this->db()->selectOne('select count(*) as c from ' . TableSet::TREATMENT)?->c,
        );

        return $expected > 0 && $actual === $expected;
    }

    private function db(): Connection
    {
        return $this->app->make('db')->connection();
    }

    private function rls(): RlsManager
    {
        return $this->app->make('rls');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter "Bench Endpoint"`
Expected: PASS (PgBouncer method skips if `:6432` down; direct + unsafe-by-construction always pass).

- [ ] **Step 5: Lint, format, commit**

```bash
composer format && composer lint
git add bench/Endpoint.php tests/Feature/Bench/EndpointTest.php
git commit -m "feat(bench): Endpoint timed K-query op + correctness sanity check"
```

---

## Task 6: JsonReporter — endpoints + latency_sweep keys

**Files:**

- Modify: `bench/Report/JsonReporter.php`
- Test: `tests/Unit/Bench/JsonReporterTest.php`

**Interfaces:**

- Consumes: current `render(array $env, array $params, array $cells, array $amortization, array $explain): array`.
- Produces:
  `render(array $env, array $params, array $cells, array $amortization, array $explain, array $endpoints, array $latencySweep): array{env,params,cells,amortization,explain,endpoints,latency_sweep}`.

- [ ] **Step 1: Update the failing test** — in `tests/Unit/Bench/JsonReporterTest.php`, both `render(...)` calls
  currently pass 5 arguments. Append two array args to each. For `renders_document()`, pass real fixtures and assert;
  for `writes_json()`, pass `[], []`:

For `renders_document()`, append after the `explain` array argument:

```php
            [
                [
                    'label' => 'direct·transaction·wrap', 'connection' => 'pgsql',
                    'strategy' => 'transaction', 'boundary' => 'wrap', 'k' => 10, 'status' => 'ok',
                    'control_us' => 10.0, 'treatment_us' => 14.0,
                    'overhead_endpoint_us' => 4.0, 'overhead_per_query_us' => 0.4,
                ],
            ],
            [
                [
                    'label' => 'direct·transaction·wrap', 'k' => 10, 'injected_ms' => 5, 'jitter_ms' => 1,
                    'control_us' => 50.0, 'treatment_us' => 90.0, 'overhead_endpoint_us' => 40.0,
                ],
            ],
```

and add assertions:

```php
        $this->assertSame('ok', $document['endpoints'][0]['status']);
        $this->assertSame(40.0, $document['latency_sweep'][0]['overhead_endpoint_us']);
```

For `writes_json()`, change the `render(...)` tail from `[], [], []` (env args aside) so the final three positional data
args become `[], [], [], []` — i.e. append `, []` twice to make cells/amortization/explain/endpoints/latencySweep all
`[]`.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter "Bench JsonReporter"`
Expected: FAIL — too few arguments to `render()` / undefined `endpoints` key.

- [ ] **Step 3: Implement** — in `bench/Report/JsonReporter.php`, extend the docblock templates and `render()`:

```php
    /**
     * @template TEnv of array<string, mixed>
     * @template TParams of array<string, mixed>
     * @template TCell of list<array<string, mixed>>
     * @template TAmortization of list<array<string, mixed>>
     * @template TExplain of list<array<string, mixed>>
     * @template TEndpoints of list<array<string, mixed>>
     * @template TLatencySweep of list<array<string, mixed>>
     *
     * @param TEnv           $env
     * @param TParams        $params
     * @param TCell          $cells
     * @param TAmortization  $amortization
     * @param TExplain       $explain
     * @param TEndpoints     $endpoints
     * @param TLatencySweep  $latencySweep
     *
     * @return array{
     *     env: TEnv,
     *     params: TParams,
     *     cells: TCell,
     *     amortization: TAmortization,
     *     explain: TExplain,
     *     endpoints: TEndpoints,
     *     latency_sweep: TLatencySweep
     * }
     */
    public function render(
        array $env,
        array $params,
        array $cells,
        array $amortization,
        array $explain,
        array $endpoints,
        array $latencySweep,
    ): array {
        return [
            'env' => $env,
            'params' => $params,
            'cells' => $cells,
            'amortization' => $amortization,
            'explain' => $explain,
            'endpoints' => $endpoints,
            'latency_sweep' => $latencySweep,
        ];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter "Bench JsonReporter"`
Expected: PASS

> Note: `bench/run.php`'s `render(...)` call is still 5-arg here and will error at runtime — Task 9 fixes it. The smoke
> test (`Bench smoke`) will be red between Task 6 and Task 9; that is expected. Do not run the smoke test in this task's
> verification.

- [ ] **Step 5: Lint, format, commit**

```bash
composer format && composer lint
git add bench/Report/JsonReporter.php tests/Unit/Bench/JsonReporterTest.php
git commit -m "feat(bench): JsonReporter emits endpoints + latency_sweep keys"
```

---

## Task 7: MarkdownReporter — endpoints/sweep tables + endpoint headline

**Files:**

- Modify: `bench/Report/MarkdownReporter.php`
- Test: `tests/Unit/Bench/MarkdownReporterTest.php`

**Interfaces:**

- Consumes: `render(array $document): string` (existing), reading `$document['endpoints']` and
  `$document['latency_sweep']`.
- Produces: `render()` now appends an `## Endpoints` table, an `## Latency sweep` table (only when non-empty), and an
  `Endpoint headline:` line via new `endpointHeadline(array $document): string`.

- [ ] **Step 1: Write the failing test** — create `tests/Unit/Bench/MarkdownReporterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Unit\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Report\MarkdownReporter;

#[TestDox('Bench MarkdownReporter')]
class MarkdownReporterTest extends TestCase
{
    #[Test]
    #[TestDox('renders endpoints table, sweep table, and endpoint headline')]
    public function renders_endpoints_and_sweep(): void
    {
        $document = [
            'cells' => [],
            'params' => ['scales' => ['100k']],
            'amortization' => [],
            'endpoints' => [
                [
                    'label' => 'direct·transaction·wrap', 'connection' => 'pgsql',
                    'strategy' => 'transaction', 'boundary' => 'wrap', 'k' => 1, 'status' => 'ok',
                    'control_us' => 10.0, 'treatment_us' => 14.0,
                    'overhead_endpoint_us' => 4.0, 'overhead_per_query_us' => 4.0,
                ],
                [
                    'label' => 'direct·transaction·wrap', 'connection' => 'pgsql',
                    'strategy' => 'transaction', 'boundary' => 'wrap', 'k' => 30, 'status' => 'ok',
                    'control_us' => 300.0, 'treatment_us' => 420.0,
                    'overhead_endpoint_us' => 120.0, 'overhead_per_query_us' => 4.0,
                ],
                [
                    'label' => 'pgbouncer·session', 'connection' => 'pgsql_pgbouncer',
                    'strategy' => 'session', 'boundary' => '—', 'k' => 10, 'status' => 'unsafe',
                    'note' => 'session GUC does not survive PgBouncer transaction pooling',
                ],
            ],
            'latency_sweep' => [
                [
                    'label' => 'direct·transaction·wrap', 'k' => 10, 'injected_ms' => 5, 'jitter_ms' => 1,
                    'control_us' => 50.0, 'treatment_us' => 90.0, 'overhead_endpoint_us' => 40.0,
                ],
            ],
        ];

        $markdown = (new MarkdownReporter())->render($document);

        $this->assertStringContainsString('## Endpoints', $markdown);
        $this->assertStringContainsString('direct·transaction·wrap', $markdown);
        $this->assertStringContainsString('unsafe', $markdown);
        $this->assertStringContainsString('## Latency sweep', $markdown);
        $this->assertStringContainsString('Endpoint headline:', $markdown);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter "Bench MarkdownReporter"`
Expected: FAIL — `## Endpoints` not found in output.

- [ ] **Step 3: Implement** — in `bench/Report/MarkdownReporter.php`, add `use function number_format;` is already
  present. In `render()`, immediately **before** the final `return implode("\n", $lines) . "\n";`, insert:

```php
        $lines[] = '';
        $lines[] = '## Endpoints';
        $lines[] = '';
        $lines[] = '| config | k | status | control (us) | treatment (us) | overhead (us) | per-query (us) |';
        $lines[] = '|---|---|---|---|---|---|---|';

        /** @var list<array<string,mixed>> $endpoints */
        $endpoints = $document['endpoints'] ?? [];

        foreach ($endpoints as $endpoint) {
            if (($endpoint['status'] ?? '') === 'unsafe') {
                $lines[] = sprintf('| %s | %s | unsafe | — | — | — | — |', $endpoint['label'], $endpoint['k']);

                continue;
            }

            $lines[] = sprintf(
                '| %s | %s | ok | %s | %s | %s | %s |',
                $endpoint['label'],
                $endpoint['k'],
                number_format((float) $endpoint['control_us'], 2),
                number_format((float) $endpoint['treatment_us'], 2),
                number_format((float) $endpoint['overhead_endpoint_us'], 2),
                number_format((float) $endpoint['overhead_per_query_us'], 2),
            );
        }

        /** @var list<array<string,mixed>> $sweep */
        $sweep = $document['latency_sweep'] ?? [];

        if ($sweep !== []) {
            $lines[] = '';
            $lines[] = '## Latency sweep';
            $lines[] = '';
            $lines[] = '| config | injected (ms) | control (us) | treatment (us) | overhead (us) |';
            $lines[] = '|---|---|---|---|---|';

            foreach ($sweep as $point) {
                $lines[] = sprintf(
                    '| %s | %s | %s | %s | %s |',
                    $point['label'],
                    $point['injected_ms'],
                    number_format((float) $point['control_us'], 2),
                    number_format((float) $point['treatment_us'], 2),
                    number_format((float) $point['overhead_endpoint_us'], 2),
                );
            }
        }

        $lines[] = '';
        $lines[] = 'Endpoint headline: ' . $this->endpointHeadline($document);
```

Then add the `endpointHeadline()` method to the class (e.g. after `headline()`):

```php
    /**
     * The request-level story: how wrap's endpoint overhead grows with K while per-query stays flat.
     *
     * @param array<string,mixed> $document
     */
    public function endpointHeadline(array $document): string
    {
        /** @var list<array<string,mixed>> $endpoints */
        $endpoints = $document['endpoints'] ?? [];

        $wrapAt = static function (int $k) use ($endpoints): ?array {
            $matches = array_filter(
                $endpoints,
                static fn(array $e): bool => ($e['label'] ?? null) === 'direct·transaction·wrap'
                    && ($e['status'] ?? null) === 'ok'
                    && ($e['k'] ?? null) === $k,
            );
            $cell = reset($matches);

            return $cell === false ? null : $cell;
        };

        $low = $wrapAt(1);
        $high = $wrapAt(30);

        if ($low === null || $high === null) {
            return 'n/a';
        }

        return sprintf(
            'wrap endpoint overhead ~%s us (k=1) -> ~%s us (k=30), ~%s us/query flat; request/session stay ~flat in k',
            number_format((float) $low['overhead_endpoint_us'], 2),
            number_format((float) $high['overhead_endpoint_us'], 2),
            number_format((float) $high['overhead_per_query_us'], 2),
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter "Bench MarkdownReporter"`
Expected: PASS

- [ ] **Step 5: Lint, format, commit**

```bash
composer format && composer lint
git add bench/Report/MarkdownReporter.php tests/Unit/Bench/MarkdownReporterTest.php
git commit -m "feat(bench): MarkdownReporter endpoints/sweep tables + endpoint headline"
```

---

## Task 8: setup-toxiproxy.sh

**Files:**

- Create: `tests/bin/setup-toxiproxy.sh`

**Interfaces:**

- Produces: a Docker Toxiproxy exposing admin `127.0.0.1:8474` and proxy listen `127.0.0.1:5433`. The harness creates
  the `postgres` proxy (listen `0.0.0.0:5433` → upstream `host.docker.internal:5432`) at run time.

- [ ] **Step 1: Create the script** — `tests/bin/setup-toxiproxy.sh` (mirrors `setup-pgbouncer.sh`):

```bash
#!/usr/bin/env bash
# Bring up Toxiproxy in front of the test Postgres (rls-pg on 5432): admin API on
# 127.0.0.1:8474 and a proxy listen port on 127.0.0.1:5433. Enables the bench latency sweep,
# which is skipped when the admin API or the proxied data path is unreachable. The harness
# creates the 'postgres' proxy (listen 0.0.0.0:5433 -> host.docker.internal:5432) at run time.
set -euo pipefail

docker rm -f rls-toxiproxy >/dev/null 2>&1 || true

docker run -d --name rls-toxiproxy \
  -p 8474:8474 -p 5433:5433 \
  ghcr.io/shopify/toxiproxy:latest

echo "toxiproxy up: admin 127.0.0.1:8474, proxy listen 127.0.0.1:5433 -> host.docker.internal:5432"
echo "the bench harness creates the 'postgres' proxy at run time"
echo "tear down with: docker rm -f rls-toxiproxy"
```

- [ ] **Step 2: Make it executable and verify it starts**

```bash
chmod +x tests/bin/setup-toxiproxy.sh
tests/bin/setup-toxiproxy.sh
curl -s http://127.0.0.1:8474/version
```

Expected: prints the container id then a Toxiproxy version string (e.g. `git-...`). If Docker is unavailable, note it
and skip live verification — the script is still committed.

- [ ] **Step 3: Commit**

```bash
git add tests/bin/setup-toxiproxy.sh
git commit -m "chore(bench): setup-toxiproxy.sh for the latency sweep"
```

---

## Task 9: run.php — endpoints phase + latency sweep

**Files:**

- Modify: `bench/run.php`
- Test: `tests/Feature/Bench/BenchSmokeTest.php`

**Interfaces:**

- Consumes: `EndpointConfig::matrix()`, `new Endpoint($app, $tables, $k)`, `Endpoint::run()`, `new Toxiproxy()`
  (`available`/`reset`/`setLatency`/`clear`), `Schema::seed('100k')`/`drop()`, `Runner::measure()`,
  `Stats::summarize()`, `JsonReporter::render(..., $endpoints, $latencySweep)`,
  `BenchmarkEnvironment::describe(..., $pgbouncer, $toxiproxy, ...)`.
- Produces: `baseline.json` with populated `endpoints` and `latency_sweep`, and `env.pgbouncer` / `env.toxiproxy`
  reflecting real availability. New CLI opts `--endpoint-iterations` (200) / `--endpoint-warmup` (20).

- [ ] **Step 1: Write the failing smoke assertions** — in `tests/Feature/Bench/BenchSmokeTest.php`, change the command
  to add endpoint iteration flags and assert the new output. Replace the `$cmd = sprintf(...)` with:

```php
        $cmd = sprintf(
            'php %s/bench/run.php --scale=1k --iterations=5 --warmup=2 '
            . '--endpoint-iterations=3 --endpoint-warmup=1 --json=%s 2>&1',
            escapeshellarg($root),
            escapeshellarg($json),
        );
```

and after the existing `scan_type` assertion, add:

```php
        $this->assertArrayHasKey('endpoints', $document);
        $this->assertNotEmpty($document['endpoints']);
        $this->assertArrayHasKey('label', $document['endpoints'][0]);
        $this->assertArrayHasKey('status', $document['endpoints'][0]);
        $this->assertArrayHasKey('k', $document['endpoints'][0]);
        $this->assertContains('direct·transaction·wrap', array_column($document['endpoints'], 'label'));
        $this->assertArrayHasKey('latency_sweep', $document);
        $this->assertArrayHasKey('toxiproxy', $document['env']);
        $this->assertArrayHasKey('pgbouncer', $document['env']);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter "Bench smoke"`
Expected: FAIL — the run errors (JsonReporter `render()` still called 5-arg in run.php) / no `endpoints` key.

- [ ] **Step 3: Add the CLI opts and use-statements** — in `bench/run.php`, add to the `getopt` long options and the new
  imports:

Change the `getopt` line to include the endpoint flags:

```php
$opts = getopt('', [
    'scale::', 'iterations::', 'warmup::', 'json::', 'md::',
    'endpoint-iterations::', 'endpoint-warmup::',
]);
```

Add imports near the other `use Radiergummi\LaravelRls\Bench\...` lines:

```php
use Radiergummi\LaravelRls\Bench\Endpoint;
use Radiergummi\LaravelRls\Bench\EndpointConfig;
use Radiergummi\LaravelRls\Bench\Toxiproxy;
```

- [ ] **Step 4: Insert the endpoints phase** — in `bench/run.php`, **after** the `foreach ($scales as $scale) { … }`
  loop closes and **before** the `$env = BenchmarkEnvironment::describe(...)` call, insert:

```php
// ---- Endpoints phase ---------------------------------------------------------------------------
// Realistic requests run many standalone queries outside one wrapping transaction. Measure the
// endpoint (establish context once, K standalone selects) across the six boundary/strategy configs,
// then sweep injected network latency on the three direct configs.
$endpointIterations = (int) ($opts['endpoint-iterations'] ?? 200);
$endpointWarmup = (int) ($opts['endpoint-warmup'] ?? 20);
$ks = [1, 10, 30];

// The per-query phase drops its tables per scale, so seed 100k here for the endpoint phase.
$tables = $schema->seed('100k');

$pgbouncerAvailable = false;

try {
    $app->make('db')->connection('pgsql_pgbouncer')->getPdo();
    $pgbouncerAvailable = true;
} catch (Throwable) {
    $pgbouncerAvailable = false;
}

$endpoints = [];

foreach (EndpointConfig::matrix() as $cfg) {
    // Omit cells whose backend isn't up (PgBouncer configs when :6432 is unreachable).
    if ($cfg->connectionName === 'pgsql_pgbouncer' && ! $pgbouncerAvailable) {
        continue;
    }

    // Config 6: unsafe by construction — flag, never measure single-client.
    if ($cfg->unsafe) {
        $endpoints[] = [
            'label' => $cfg->label,
            'connection' => $cfg->connectionName,
            'strategy' => $cfg->strategy,
            'boundary' => $cfg->boundaryLabel,
            'k' => 10,
            'status' => 'unsafe',
            'note' => 'session GUC does not survive PgBouncer transaction pooling',
        ];

        continue;
    }

    $app['config']->set('database.default', $cfg->connectionName);
    $app['config']->set('rls.strategy', $cfg->strategy);

    try {
        foreach ($ks as $k) {
            $endpoint = new Endpoint($app, $tables, $k);

            $control = Stats::summarize($runner->measure(
                static fn(Variant $v) => $endpoint->run($cfg, 'control'),
                Variant::Control,
                $endpointWarmup,
                $endpointIterations,
            ))['mean_us'];

            $treatment = Stats::summarize($runner->measure(
                static fn(Variant $v) => $endpoint->run($cfg, 'treatment'),
                Variant::Treatment,
                $endpointWarmup,
                $endpointIterations,
            ))['mean_us'];

            $overhead = $treatment - $control;
            $endpoints[] = [
                'label' => $cfg->label,
                'connection' => $cfg->connectionName,
                'strategy' => $cfg->strategy,
                'boundary' => $cfg->boundaryLabel,
                'k' => $k,
                'status' => 'ok',
                'control_us' => $control,
                'treatment_us' => $treatment,
                'overhead_endpoint_us' => $overhead,
                'overhead_per_query_us' => $overhead / $k,
            ];
        }
    } finally {
        // Reset the session context WHILE rls.strategy is still 'session' (else the reset is a
        // silent no-op and the GUC leaks), THEN restore the default connection + strategy.
        $rls->forget();
        $app->make('db')->connection($cfg->connectionName)->resetSessionContext();
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('rls.strategy', 'transaction');
    }
}

// ---- Latency sweep -----------------------------------------------------------------------------
// Rebind the three direct configs onto pgsql_delayed (port 5433) so queries traverse the Toxiproxy
// proxy carrying the injected latency; gate on a real select 1 through that data path.
$toxiproxy = new Toxiproxy();
$toxiproxyAvailable = false;
$latencySweep = [];
$sweepPoints = [[0, 0], [1, 0], [5, 1]]; // {0, 1ms, 5ms+-1}; Toxiproxy latency/jitter are integer ms

if ($toxiproxy->available()) {
    $toxiproxy->reset('postgres', '0.0.0.0:5433', 'host.docker.internal:5432');

    $dataPathOk = false;

    try {
        $app->make('db')->connection('pgsql_delayed')->select('select 1');
        $dataPathOk = true;
    } catch (Throwable) {
        $dataPathOk = false;
    }

    if ($dataPathOk) {
        $toxiproxyAvailable = true;

        foreach (array_slice(EndpointConfig::matrix(), 0, 3) as $cfg) {
            $app['config']->set('database.default', 'pgsql_delayed');
            $app['config']->set('rls.strategy', $cfg->strategy);

            try {
                foreach ($sweepPoints as [$ms, $jitter]) {
                    $toxiproxy->setLatency('postgres', $ms, $jitter);
                    $endpoint = new Endpoint($app, $tables, 10);

                    $control = Stats::summarize($runner->measure(
                        static fn(Variant $v) => $endpoint->run($cfg, 'control'),
                        Variant::Control,
                        $endpointWarmup,
                        $endpointIterations,
                    ))['mean_us'];

                    $treatment = Stats::summarize($runner->measure(
                        static fn(Variant $v) => $endpoint->run($cfg, 'treatment'),
                        Variant::Treatment,
                        $endpointWarmup,
                        $endpointIterations,
                    ))['mean_us'];

                    $latencySweep[] = [
                        'label' => $cfg->label,
                        'k' => 10,
                        'injected_ms' => $ms,
                        'jitter_ms' => $jitter,
                        'control_us' => $control,
                        'treatment_us' => $treatment,
                        'overhead_endpoint_us' => $treatment - $control,
                    ];
                }
            } finally {
                $rls->forget();
                $app->make('db')->connection('pgsql_delayed')->resetSessionContext();
                $app['config']->set('database.default', 'pgsql');
                $app['config']->set('rls.strategy', 'transaction');
            }
        }

        $toxiproxy->clear('postgres');
    }
}

$schema->drop();
```

- [ ] **Step 5: Wire availability + new keys into the reporters** — in `bench/run.php`, update the `describe()` call to
  pass the real availability booleans:

```php
    pgbouncer: $pgbouncerAvailable,
    toxiproxy: $toxiproxyAvailable,
    emulatePrepares: (bool) $db->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES),
```

and update the `$json->render(...)` call to pass `$endpoints` + `$latencySweep` and add the endpoint params:

```php
$document = $json->render(
    $env,
    [
        'iterations' => $iterations,
        'warmup' => $warmup,
        'scales' => $scales,
        'endpoint_iterations' => $endpointIterations,
        'endpoint_warmup' => $endpointWarmup,
    ],
    $cells,
    $amortization,
    $explain,
    $endpoints,
    $latencySweep,
);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter "Bench smoke"`
Expected: PASS — `endpoints` non-empty with the direct configs; `latency_sweep` present (empty if Toxiproxy down); `env`
has `pgbouncer` + `toxiproxy`.

- [ ] **Step 7: Run the whole suite to confirm nothing regressed**

Run: `composer test`
Expected: PASS (PgBouncer/Toxiproxy-gated tests skip if their services are down).

- [ ] **Step 8: Lint, format, commit**

```bash
composer format && composer lint
git add bench/run.php tests/Feature/Bench/BenchSmokeTest.php
git commit -m "feat(bench): endpoints phase + latency sweep in run.php"
```

---

## Task 10: Regenerate the committed baseline + full verification

**Files:**

- Modify: `bench/baseline.json` (regenerated artifact)

**Interfaces:**

- Consumes: the full stack from Tasks 1–9 plus a running Postgres, PgBouncer, and Toxiproxy.

- [ ] **Step 1: Bring up all infra** (so the committed baseline includes PgBouncer + latency cells)

```bash
tests/bin/setup-db.sh
tests/bin/setup-pgbouncer.sh
tests/bin/setup-toxiproxy.sh
```

Expected: Postgres on 5432, PgBouncer on 6432, Toxiproxy admin on 8474 + listen on 5433. If any cannot start (no
Docker), note which cells will be omitted and proceed — the run must still succeed.

- [ ] **Step 2: Run the full bench and inspect the markdown**

Run: `composer bench`
Expected: an `## Endpoints` table (direct configs at K∈{1,10,30}; `pgbouncer·*` rows when `:6432` is up;
`pgbouncer·session` shown `unsafe`), a `## Latency sweep` table when Toxiproxy is up, and an `Endpoint headline:` line.
`bench/baseline.json` is rewritten with populated `endpoints` + `latency_sweep` and `env.pgbouncer` / `env.toxiproxy`.

- [ ] **Step 3: Sanity-check the thesis in the numbers**

Read `bench/baseline.json`. Confirm:

- `direct·transaction·wrap` `overhead_per_query_us` is roughly flat across K while `overhead_endpoint_us` grows ~
  linearly with K.
- `direct·transaction·request` and `direct·session` `overhead_endpoint_us` stay ~flat across K.
- If the sweep ran: `direct·transaction·wrap` `overhead_endpoint_us` grows with `injected_ms` while `request`/`session`
  stay ~flat.

If the shape contradicts the thesis, stop and investigate (do not massage the baseline) — see systematic-debugging.

- [ ] **Step 4: Final gates**

```bash
composer test && composer lint && composer format
```

Expected: suite green, PHPStan clean, Pint clean (no diff).

- [ ] **Step 5: Commit the baseline**

```bash
git add bench/baseline.json
git commit -m "chore(bench): regenerate baseline with endpoint + latency-sweep cells"
```

- [ ] **Step 6: Tear down infra (optional)**

```bash
docker rm -f rls-toxiproxy rls-pgbouncer
```

---

## Self-Review

**Spec coverage:**

- Endpoint definition, control/treatment, `overhead_endpoint`/`overhead_per_query`, scale 100k, K∈{1,10,30} → Tasks 5,
  9. ✔
- Seeding its own 100k (C1) → Task 9 Step 4 (`$schema->seed('100k')`, drop at end). ✔
- Six-config matrix + config 6 unsafe by construction (C2) → Tasks 2, 5, 9. ✔
- Latency sweep rebinds onto `pgsql_delayed` + real `select 1` data-path gate (I1) → Task 9 Step 4. ✔
- One `try/finally` per cell; reset session context while strategy still `session`, then restore (I2) → Tasks 5, 9. ✔
- `pgsql_pgbouncer` `sslmode=disable` + emulate_prepares; `pgsql_delayed` emulate_prepares (M2) → Task 1. ✔
- `toxiproxy` env flag (M2) → Task 4. ✔
- 200 iters load-bearing (M1) → Global Constraints + Task 9 defaults. ✔
- `EndpointConfig`, `Endpoint`, `Toxiproxy`, `Boot`, `JsonReporter`, `MarkdownReporter`, `setup-toxiproxy.sh` → Tasks
  1–3, 6–8. ✔
- Tests: `EndpointTest`, `ToxiproxyTest`, extended smoke (+ `EndpointConfigTest`, `MarkdownReporterTest`) → Tasks 2, 3,
  5, 7, 9. ✔
- Dev-only, no `src/` changes → Global Constraints; all paths under `bench/`, `tests/`, `tests/bin/`. ✔

**Type consistency:** `EndpointConfig::matrix()` and the six fields (incl. `unsafe`) are used identically in Tasks
2/5/9; `Endpoint::run($cfg, $variant)` / `treatmentIsCorrect($cfg)` signatures match across Task 5 and Task 9;
`JsonReporter::render(...7 args)` matches Task 6 and Task 9; `describe(..., pgbouncer, toxiproxy, emulatePrepares)`
order matches Task 4 and Task 9; `Toxiproxy` method names (`available`/`reset`/`setLatency`/`clear`/`payload`) match
Tasks 3 and 9.

**Known deliberate cost:** the smoke test now triggers a 100k seed (endpoints phase) on every `composer test`, adding a
few seconds. This is inherent to the spec's fixed-100k endpoint scale; accepted, not a defect.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-06-endpoint-perf-cells.md`. Two execution options:

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
