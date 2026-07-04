<?php

namespace Radiergummi\LaravelRls\Context;

/**
 * Declares the app's context dimensions and their Postgres types, used to
 * generate typed SQL helpers (rls.tenant_id()) and typed PHP accessors.
 */
class ContextSchema
{
    /** @var array<string, string> name => pg type */
    private array $dimensions = [];

    public function uuid(string $name): self
    {
        return $this->add($name, 'uuid');
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
        return isset($this->dimensions[$name]);
    }

    /**
     * Whether the given value is a well-formed instance of the dimension's
     * declared Postgres type. Undeclared dimensions are unconstrained.
     */
    public function matches(string $name, mixed $value): bool
    {
        $type = $this->dimensions[$name] ?? null;

        if ($type === null) {
            return true;
        }

        return match ($type) {
            'uuid' => ($value instanceof \Stringable || is_string($value))
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $value) === 1,
            'integer', 'bigint' => is_int($value)
                || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'boolean' => is_bool($value)
                || in_array($value, [0, 1, '0', '1', 'true', 'false', 't', 'f'], true),
            'text' => is_string($value) || is_int($value) || is_float($value) || $value instanceof \Stringable,
            default => true,
        };
    }

    /** @return array<string, string> */
    public function dimensions(): array
    {
        return $this->dimensions;
    }

    /**
     * Generate a typed helper per dimension, e.g.
     * rls.tenant_id() returns uuid := rls.context('tenant_id')::uuid.
     *
     * @return array<int, string>
     */
    public function functionStatements(): array
    {
        $statements = [];

        foreach ($this->dimensions as $name => $type) {
            $statements[] = "create or replace function rls.{$name}() " .
                "returns {$type} language sql stable as $$ " .
                "select rls.context('{$name}')::{$type} $$";
        }

        return $statements;
    }

    private function add(string $name, string $type): self
    {
        $this->dimensions[$name] = $type;

        return $this;
    }
}
