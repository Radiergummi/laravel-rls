<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Context;

final readonly class RlsContext
{
    private function __construct(
        private array $values,
        private bool $bypass = false,
        private ?string $reason = null,
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
