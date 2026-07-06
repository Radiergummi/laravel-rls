<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Context;

use function array_key_exists;

final readonly class RlsContext
{
    /**
     * @param array<string, null|scalar> $values
     */
    private function __construct(
        private array $values,
    ) {}

    /**
     * @param array<string, null|scalar> $values
     */
    public static function make(array $values): self
    {
        return new self($values);
    }

    /**
     * @return array<string, null|scalar>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * @return null|scalar
     */
    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * @param array<string, null|scalar> $values
     */
    public function with(array $values): self
    {
        return new self([...$this->values, ...$values]);
    }
}
