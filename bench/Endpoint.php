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

    public function run(EndpointConfig $cfg, Variant $variant): void
    {
        // Resolve the active connection once per timed op — not per query — so container/resolver
        // lookups don't inflate the very quantity we measure.
        $connection = $this->db();

        if ($variant === Variant::Control) {
            $this->repeat(
                $connection,
                'select * from ' . TableSet::CONTROL . ' where id = ? and tenant_id = ?',
                [$this->tables->probeRowId, $this->tables->probeTenantId],
            );

            return;
        }

        $sql = 'select * from ' . TableSet::TREATMENT . ' where id = ?';
        $bindings = [$this->tables->probeRowId];

        $this->rls()->isolateTo(
            ['tenant_id' => $this->tables->probeTenantId],
            function () use ($cfg, $connection, $sql, $bindings): void {
                // request boundary: one transaction wraps all K selects (context injected once at
                // BEGIN). Otherwise each standalone select auto-wraps (wrap) or runs on the session
                // GUC (session).
                $cfg->oneTransaction
                    ? $connection->transaction(fn() => $this->repeat($connection, $sql, $bindings))
                    : $this->repeat($connection, $sql, $bindings);
            },
        );
    }

    /**
     * Run the same select K times on the given connection (the timed unit of work).
     *
     * @param array<int, mixed> $bindings
     */
    private function repeat(Connection $connection, string $sql, array $bindings): void
    {
        for ($i = 0; $i < $this->k; $i++) {
            $connection->select($sql, $bindings);
        }
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
