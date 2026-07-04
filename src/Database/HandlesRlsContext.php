<?php

namespace Radiergummi\LaravelRls\Database;

use Closure;

trait HandlesRlsContext
{
    /**
     * Dimension keys we set on the current transaction, so we can blank them
     * when the context changes or is popped (transaction-local GUCs have no
     * "unset", so we reset to empty string, which rls.context() reads as NULL).
     *
     * @var list<string>
     */
    private array $rlsAppliedKeys = [];

    private bool $inRlsGuard = false;

    public function beginTransaction(): void
    {
        parent::beginTransaction();

        if ($this->transactionLevel() === 1) {
            $this->rlsAppliedKeys = [];
            $this->applyRlsContext();
        }
    }

    protected function run($query, $bindings, Closure $callback): mixed
    {
        $this->guardRlsBoundary($query);

        if ($this->shouldWrapForRls()) {
            return $this->transaction(fn () => parent::run($query, $bindings, $callback));
        }

        return parent::run($query, $bindings, $callback);
    }

    /**
     * Enforce the fail-loud guard and the explicit-boundary mode. Both are
     * opt-in; when neither is enabled this is skipped entirely so default-mode
     * behaviour (DB fail-closed + wrap) is untouched.
     */
    protected function guardRlsBoundary(string $query): void
    {
        if ($this->inRlsGuard) {
            return;
        }

        $failLoud = config('rls.on_missing_context', 'closed') === 'throw';
        $explicit = config('rls.boundary', 'wrap') === 'explicit';

        if (! $failLoud && ! $explicit) {
            return;
        }

        // Only guard data access; schema/DDL statements are never confined.
        if (! preg_match('/^\s*(select|insert|update|delete)\b/i', $query)) {
            return;
        }

        if (! $this->queryTouchesManagedTable($query)) {
            return;
        }

        $manager = app('rls');

        if ($manager->current()?->isBypass()) {
            return;
        }

        if (! $manager->hasContext()) {
            if ($failLoud) {
                throw \Radiergummi\LaravelRls\Exceptions\MissingTenantContext::forQuery($query);
            }

            return;
        }

        if ($explicit && $this->transactionLevel() === 0) {
            throw \Radiergummi\LaravelRls\Exceptions\MissingContextBoundary::forQuery($query);
        }
    }

    private function queryTouchesManagedTable(string $query): bool
    {
        foreach ($this->managedTableNames() as $table) {
            if (str_contains($query, '"' . $table . '"')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tables in the current schema that have RLS enabled. The re-entry flag
     * stops the introspection query from recursing back through the guard.
     *
     * @return list<string>
     */
    private function managedTableNames(): array
    {
        $sql = "select c.relname as name from pg_class c " .
            "join pg_namespace n on n.oid = c.relnamespace " .
            "where c.relrowsecurity and c.relkind = 'r' and n.nspname = current_schema()";

        $this->inRlsGuard = true;

        try {
            $rows = $this->select($sql);
        } finally {
            $this->inRlsGuard = false;
        }

        return array_map(fn ($row) => $row->name, $rows);
    }

    protected function shouldWrapForRls(): bool
    {
        return $this->transactionLevel() === 0
            && config('rls.strategy', 'transaction') === 'transaction'
            && config('rls.boundary', 'wrap') === 'wrap'
            && app('rls')->hasContext();
    }

    /**
     * Set transaction-local GUCs for the current context (idempotent).
     * A no-op outside a transaction — context is injected at the next
     * beginTransaction() instead.
     */
    public function applyRlsContext(): void
    {
        // Transaction strategy binds context to a transaction, so there is
        // nothing to set until one is open. Session strategy sets a session
        // GUC that persists without a transaction.
        $local = config('rls.strategy', 'transaction') !== 'session';

        if ($local && $this->transactionLevel() === 0) {
            return;
        }

        $prefix = config('rls.prefix', 'app.');
        $context = app('rls')->current();

        // Blank any dimension keys we previously set, so popping/switching
        // context cannot leave a stale value behind.
        foreach ($this->rlsAppliedKeys as $key) {
            $this->setConfig($prefix . $key, '', $local);
        }
        $this->rlsAppliedKeys = [];

        $this->setConfig($prefix . 'bypass', $context?->isBypass() ? 'on' : '', $local);

        if ($context !== null && ! $context->isBypass()) {
            foreach ($context->values() as $key => $value) {
                $this->setConfig($prefix . $key, (string) $value, $local);
                $this->rlsAppliedKeys[] = $key;
            }
        }
    }

    private function setConfig(string $name, string $value, bool $local): void
    {
        // is_local is inlined as a literal (not bound) so no boolean-binding
        // ambiguity; name and value stay bound and injection-safe.
        $flag = $local ? 'true' : 'false';
        $this->statement("select set_config(?, ?, {$flag})", [$name, $value]);
    }
}
