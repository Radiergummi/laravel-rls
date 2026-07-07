<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Database;

use Closure;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Radiergummi\LaravelRls\Context\RlsManager;
use Radiergummi\LaravelRls\Exceptions\MissingContextBoundary;
use Radiergummi\LaravelRls\Exceptions\MissingIsolationContext;
use Radiergummi\LaravelRls\Exceptions\NestedTenantContext;
use Throwable;

use function array_values;
use function assert;
use function is_string;

trait HandlesRlsContext
{
    /**
     * Isolation keys we set on the current transaction, so we can blank them when the context
     * changes or is popped (transaction-local GUCs have no "unset", so we reset to empty string,
     * which rls.context() reads as NULL).
     *
     * @var list<string>
     */
    private array $rlsAppliedKeys = [];

    /**
     * The stringified GUC value we last applied for each key. Lets us detect a mid-transaction scope
     * switch (an already-applied isolation key taking a different value while a transaction is open).
     *
     * @var array<string, string>
     */
    private array $rlsAppliedValues = [];

    private bool $inRlsGuard = false;

    public function beginTransaction(): void
    {
        parent::beginTransaction();

        if ($this->transactionLevel() === 1) {
            // A fresh transaction has none of the previous transaction's GUCs (they died at COMMIT),
            // so clear the applied-value record too — otherwise the nested-change guard would compare
            // this transaction's context against a prior, unrelated transaction's scope.
            $this->rlsAppliedKeys = [];
            $this->rlsAppliedValues = [];
            $this->applyRlsContext();
        }
    }

    /**
     * Set transaction-local GUCs for the current context (idempotent).
     *
     * A no-op outside a transaction — context is injected at the next beginTransaction() instead.
     *
     * @throws InvalidArgumentException
     */
    public function applyRlsContext(): void
    {
        // Transaction strategy binds context to a transaction, so there is nothing to set until one
        // is open. Session strategy sets a session GUC that persists without a transaction.
        $local = config('rls.strategy', 'transaction') !== 'session';

        if ($local && $this->transactionLevel() === 0) {
            return;
        }

        $prefix = config('rls.prefix', 'app.');
        assert(is_string($prefix));
        $context = app(RlsManager::class)->current();

        $next = [];

        if ($context !== null) {
            foreach ($context->values() as $key => $value) {
                $next[$key] = $this->stringifyGucValue($value);
            }
        }

        // Nested-transaction tenant-change guard: within an open transaction (transaction strategy),
        // an already-applied isolation key must not switch to a different non-empty value — the
        // transaction would silently straddle two scopes. Blanking a key (switch to '') is allowed:
        // that is a pop restoring the pre-transaction state, not a scope straddle.
        //
        // Opt-in ('throw'), because the standard RefreshDatabase/DatabaseTransactions test harness
        // wraps every test in one transaction — so a normal "seed as A, assert as B" test would trip
        // an always-on guard. Production code under the default `wrap` boundary never hits it either:
        // there is no open transaction between queries, so a nested isolateTo to another scope is at
        // transaction level 0. It fires only for genuine cross-scope writes inside an explicit
        // transaction.
        $guarding = config('rls.on_nested_change', 'allow') === 'throw';

        if ($guarding && $local && $this->transactionLevel() >= 1) {
            foreach ($this->rlsAppliedValues as $key => $applied) {
                if ($applied === '') {
                    continue;
                }

                $incoming = $next[$key] ?? '';

                if ($incoming !== '' && $incoming !== $applied) {
                    throw NestedTenantContext::forChangedKey($key, $applied, $incoming);
                }
            }
        }

        // Blank any isolation keys we previously set, so popping/switching context cannot leave a
        // stale value behind.
        foreach ($this->rlsAppliedKeys as $key) {
            $this->setConfig($prefix . $key, '', $local);
        }

        $this->rlsAppliedKeys = [];
        $this->rlsAppliedValues = [];

        foreach ($next as $key => $value) {
            $this->setConfig($prefix . $key, $value, $local);
            $this->rlsAppliedKeys[] = $key;
            $this->rlsAppliedValues[$key] = $value;
        }
    }

    private function setConfig(string $name, string $value, bool $local): void
    {
        // is_local is inlined as a literal (not bound) so no boolean-binding ambiguity; name and
        // value stay bound and injection-safe.
        $flag = $local ? 'true' : 'false';
        $this->statement("select set_config(?, ?, {$flag})", [$name, $value]);

        // Session GUCs live per backend session. A read/write split has a separate read PDO (the
        // replica) that plain SELECT's route to, so the context must be mirrored there too.
        // Transaction-local GUCs need no mirroring: in-transaction reads use the Write PDO.
        if (!$local && $this->hasDistinctReadPdo()) {
            $this->select(
                "select set_config(?, ?, {$flag})",
                [$name, $value],
                useReadPdo: true,
            );
        }
    }

    private function hasDistinctReadPdo(): bool
    {
        return $this->transactionLevel() === 0
            && $this->getReadPdo() !== $this->getPdo();
    }

    /**
     * Serialize a context value into its GUC string.
     *
     * Booleans must map to a Postgres boolean literal rather than PHP's (string) cast: (string)
     * false is '', which rls.context() reads as NULL — collapsing a `false` scope into "no context"
     * and silently mis-scoping every row. null stays '' on purpose (the fail-closed sentinel).
     *
     * @param null|scalar $value
     */
    private function stringifyGucValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * Blank every session GUC we set on this connection.
     *
     * Under the session strategy a GUC persists on the pooled connection and would otherwise carry
     * one request/job's context into the next (Octane resets the scoped context stack between
     * requests, but not the persistent connection). Wired to worker boundaries from the service
     * provider. A no-op under the transaction strategy, where GUCs die with their transaction.
     */
    public function resetSessionContext(): void
    {
        if (config('rls.strategy', 'transaction') !== 'session') {
            return;
        }

        $prefix = config('rls.prefix', 'app.');
        assert(is_string($prefix));

        foreach ($this->rlsAppliedKeys as $key) {
            $this->setConfig($prefix . $key, '', false);
        }
        $this->rlsAppliedKeys = [];
        $this->rlsAppliedValues = [];
    }

    /**
     * After a dropped connection is re-established, the fresh backend has none of our session GUCs.
     *
     * Re-apply the current context so the session strategy does not silently lose it (queries would
     * run unscoped / fail-closed).
     *
     * @throws LostConnectionException
     * @throws InvalidArgumentException
     */
    public function reconnect(): mixed
    {
        $result = parent::reconnect();

        if (config('rls.strategy', 'transaction') === 'session') {
            $this->rlsAppliedKeys = [];
            $this->rlsAppliedValues = [];
            $this->applyRlsContext();
        }

        return $result;
    }

    /**
     * @param array<int, mixed>                                $bindings
     * @param Closure(string, array<int|string, mixed>): mixed $callback
     *
     * @throws MissingIsolationContext
     * @throws Throwable
     * @throws MissingContextBoundary
     * @throws QueryException
     */
    protected function run($query, $bindings, Closure $callback): mixed
    {
        $this->guardRlsBoundary($query);

        if ($this->shouldWrapForRls()) {
            return $this->transaction(fn() => parent::run($query, $bindings, $callback));
        }

        return parent::run($query, $bindings, $callback);
    }

    /**
     * Enforce the fail-loud guard and the explicit-boundary mode. Both are opt-in; when neither is
     *  enabled, this is skipped entirely, so default-mode behavior (DB fail-closed and wrap)
     * is untouched.
     *
     * @throws MissingContextBoundary
     * @throws MissingIsolationContext
     */
    protected function guardRlsBoundary(string $query): void
    {
        if ($this->inRlsGuard) {
            return;
        }

        $failLoud = config('rls.on_missing_context', 'closed') === 'throw';
        $explicit = config('rls.boundary', 'wrap') === 'explicit';

        if (!$failLoud && !$explicit) {
            return;
        }

        // Only guard data access; schema/DDL statements are never confined.
        if (!preg_match('/^\s*(select|insert|update|delete)\b/i', $query)) {
            return;
        }

        if (!$this->queryTouchesManagedTable($query)) {
            return;
        }

        $manager = app(RlsManager::class);
        assert($manager instanceof RlsManager);

        // Bypass runs on the admin connection with the in-flight flag set; the guard stands down for
        // its duration (there is no bypass context on the stack anymore).
        if ($manager->isBypassing()) {
            return;
        }

        if (!$manager->hasContext()) {
            if ($failLoud) {
                throw MissingIsolationContext::forQuery($query);
            }

            return;
        }

        if ($explicit && $this->transactionLevel() === 0) {
            throw MissingContextBoundary::forQuery($query);
        }
    }

    private function queryTouchesManagedTable(string $query): bool
    {
        foreach ($this->managedTableNames() as $table) {
            if (str_contains($query, sprintf('"%s"', $table))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tables in the current schema that have RLS enabled. The re-entry flag stops the introspection
     * query from recursing back through the guard.
     *
     * @return list<string>
     */
    private function managedTableNames(): array
    {
        $this->inRlsGuard = true;

        try {
            /** @var list<object{name: string}> $rows */
            $rows = $this->select(
                'select c.relname as name from pg_class c '
                . 'join pg_namespace n on n.oid = c.relnamespace '
                . "where c.relrowsecurity and c.relkind = 'r' and n.nspname = current_schema()",
            );
        } finally {
            $this->inRlsGuard = false;
        }

        return array_values(
            array_map(
                static fn(object $row): string => $row->name,
                $rows,
            ),
        );
    }

    protected function shouldWrapForRls(): bool
    {
        return $this->transactionLevel() === 0
            && config('rls.strategy', 'transaction') === 'transaction'
            && config('rls.boundary', 'wrap') === 'wrap'
            && app(RlsManager::class)->hasContext();
    }
}
