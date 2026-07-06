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
