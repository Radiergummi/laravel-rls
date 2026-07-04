<?php

namespace Radiergummi\Rls\Context;

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
