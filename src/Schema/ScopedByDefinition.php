<?php

namespace Radiergummi\LaravelRls\Schema;

use Closure;

/**
 * Returned by the scopedBy() blueprint macro to allow fluent opt-in extras,
 * e.g. $table->scopedBy('tenant_id')->withDefault().
 */
class ScopedByDefinition
{
    /** @param Closure(string): void $addRaw adds an rlsRaw blueprint command */
    public function __construct(
        private readonly Closure $addRaw,
        private readonly string $table,
        private readonly string $column,
        private readonly string $dimension,
        private readonly string $type,
    ) {}

    /**
     * Default the scoping column to the current context value, so an insert that
     * omits it is auto-filled. With the policy's WITH CHECK this makes the
     * scope id "impossible to get wrong".
     */
    public function withDefault(): self
    {
        ($this->addRaw)(
            "alter table \"{$this->table}\" alter column \"{$this->column}\" " .
            "set default rls.context('{$this->dimension}')::{$this->type}",
        );

        return $this;
    }
}
