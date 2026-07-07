<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Context;

use Illuminate\Support\Str;
use Stringable;

/**
 * Declares the app's isolation keys and their Postgres types, used to generate typed SQL
 * helpers (rls.tenant_id()) and typed PHP accessors.
 */
class ContextSchema
{
    /**
     * name => pg type
     *
     * @var array<string, string>
     */
    private array $isolationKeys = [];

    public function uuid(string $name): self
    {
        return $this->add($name, 'uuid');
    }

    private function add(string $name, string $type): self
    {
        $this->isolationKeys[$name] = $type;

        return $this;
    }

    public function integer(string $name): self
    {
        return $this->add($name, 'integer');
    }

    public function bigInteger(string $name): self
    {
        return $this->add($name, 'bigint');
    }

    public function string(string $name): self
    {
        return $this->add($name, 'text');
    }

    public function boolean(string $name): self
    {
        return $this->add($name, 'boolean');
    }

    public function has(string $name): bool
    {
        return isset($this->isolationKeys[$name]);
    }

    /**
     * Whether the given value is a well-formed instance of the isolation key's declared Postgres type.
     *
     * Undeclared isolation keys are unconstrained.
     */
    public function matches(string $name, mixed $value): bool
    {
        $type = $this->isolationKeys[$name] ?? null;

        if ($type === null) {
            return true;
        }

        return match ($type) {
            'uuid' => ($value instanceof Stringable || is_string($value))
                && Str::isUuid((string) $value),
            // Range-check against the declared Postgres integer width: a value that overflows
            // int4/int8 would pass a shape-only check but throw on the ::integer/::bigint cast in
            // every query. filter_var's default max on 64-bit PHP is int8's max, so a bare
            // validating covers bigint.
            'integer' => filter_var($value, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => -2147483648,
                    'max_range' => 2147483647,
                ],
            ]) !== false,
            'bigint' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'boolean' => is_bool($value)
                || in_array(
                    $value,
                    [0, 1, '0', '1', 'true', 'false', 't', 'f'],
                    true,
                ),
            'text' => is_string($value)
                || is_int($value)
                || is_float($value)
                || $value instanceof Stringable,
            default => true,
        };
    }

    /**
     * @return array<string, string>
     */
    public function isolationKeys(): array
    {
        return $this->isolationKeys;
    }

    /**
     * Generate a typed helper per isolation key, e.g., `rls.tenant_id()` returns
     * `uuid := rls.context('tenant_id')::uuid`.
     *
     * @return array<int, string>
     */
    public function functionStatements(): array
    {
        $statements = [];

        foreach ($this->isolationKeys as $name => $type) {
            // parallel safe, like rls.context(): Postgres derives a query's parallel-safety from the
            // RLS policy's declared function, so an unsafe helper used in a policy would silently
            // force a serial plan on the isolated table.
            $statements[] = sprintf(
                'create or replace function rls.%s() returns %s language sql stable parallel safe as $$'
                . " select rls.context('%s')::%s $$",
                $name,
                $type,
                $name,
                $type,
            );
        }

        return $statements;
    }
}
