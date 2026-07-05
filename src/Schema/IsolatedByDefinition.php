<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Schema;

use Closure;

/**
 * Returned by the `isolatedBy()` blueprint macro to allow fluent opt-in extras, e.g.,
 * `$table->isolatedBy('org_id')->withDefault()`.
 */
readonly class IsolatedByDefinition
{
    /**
     * @param Closure(string): void $addRaw adds an rlsRaw blueprint command
     */
    public function __construct(
        private Closure $addRaw,
        private string $table,
        private string $column,
        private string $key,
        private string $type,
    ) {}

    /**
     * Default the scoping column to the current context value, so an insert that omits it
     * is autofilled.
     *
     * With the policy's WITH CHECK this makes the scope id "impossible to get wrong".
     */
    public function withDefault(): self
    {
        ($this->addRaw)(
            sprintf(
                'alter table "%s" alter column "%s" set default rls.context(\'%s\')::%s',
                $this->table,
                $this->column,
                $this->key,
                $this->type,
            ),
        );

        return $this;
    }
}
