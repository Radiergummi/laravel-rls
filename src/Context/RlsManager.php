<?php

namespace Radiergummi\Rls\Context;

use Closure;

class RlsManager
{
    /** @var list<RlsContext> */
    private array $stack = [];

    private ?Closure $sync = null;

    public function setSyncCallback(?Closure $sync): void
    {
        $this->sync = $sync;
    }

    public function push(RlsContext $context): void
    {
        $this->stack[] = $context;
        $this->afterChange();
    }

    public function pop(): void
    {
        array_pop($this->stack);
        $this->afterChange();
    }

    public function current(): ?RlsContext
    {
        return $this->stack === [] ? null : $this->stack[count($this->stack) - 1];
    }

    public function hasContext(): bool
    {
        return $this->stack !== [];
    }

    public function context(): array
    {
        return $this->current()?->values() ?? [];
    }

    public function get(string $key): mixed
    {
        return $this->current()?->get($key);
    }

    public function set(string $key, mixed $value): void
    {
        $current = $this->current();
        $this->pop();
        $this->push(($current ?? RlsContext::make([]))->with([$key => $value]));
    }

    public function actingAs(array $context, ?Closure $callback = null): mixed
    {
        return $this->enter(RlsContext::make($context), $callback);
    }

    public function withoutRls(string $reason, Closure $callback): mixed
    {
        return $this->enter(RlsContext::bypass($reason), $callback);
    }

    public function system(string $reason, Closure $callback): mixed
    {
        return $this->withoutRls($reason, $callback);
    }

    public function forget(): void
    {
        $this->stack = [];
        $this->afterChange();
    }

    private function enter(RlsContext $context, ?Closure $callback): mixed
    {
        $this->push($context);

        if ($callback === null) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $this->pop();
        }
    }

    private function afterChange(): void
    {
        if ($this->sync !== null) {
            ($this->sync)();
        }
    }
}
