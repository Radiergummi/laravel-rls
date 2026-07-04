<?php

namespace Radiergummi\Rls\Database;

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

    public function beginTransaction(): void
    {
        parent::beginTransaction();

        if ($this->transactionLevel() === 1) {
            $this->rlsAppliedKeys = [];
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
     * A no-op outside a transaction — context is injected at the next
     * beginTransaction() instead.
     */
    public function applyRlsContext(): void
    {
        if ($this->transactionLevel() === 0) {
            return;
        }

        $prefix = config('rls.prefix', 'app.');
        $context = app('rls')->current();

        // Blank any dimension keys we previously set, so popping/switching
        // context cannot leave a stale value behind within the transaction.
        foreach ($this->rlsAppliedKeys as $key) {
            $this->setLocalConfig($prefix . $key, '');
        }
        $this->rlsAppliedKeys = [];

        $this->setLocalConfig($prefix . 'bypass', $context?->isBypass() ? 'on' : '');

        if ($context !== null && ! $context->isBypass()) {
            foreach ($context->values() as $key => $value) {
                $this->setLocalConfig($prefix . $key, (string) $value);
                $this->rlsAppliedKeys[] = $key;
            }
        }
    }

    private function setLocalConfig(string $name, string $value): void
    {
        $this->statement('select set_config(?, ?, true)', [$name, $value]);
    }
}
