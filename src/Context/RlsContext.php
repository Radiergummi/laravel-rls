<?php

namespace Radiergummi\Rls\Context;

final class RlsContext
{
    private function __construct(
        private readonly array $values,
        private readonly bool $bypass = false,
        private readonly ?string $reason = null,
    ) {}

    public static function make(array $values): self
    {
        return new self($values);
    }

    public static function bypass(string $reason): self
    {
        return new self([], true, $reason);
    }

    public function values(): array
    {
        return $this->values;
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function with(array $values): self
    {
        return new self(array_merge($this->values, $values), $this->bypass, $this->reason);
    }

    public function isBypass(): bool
    {
        return $this->bypass;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
