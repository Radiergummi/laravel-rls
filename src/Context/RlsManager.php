<?php

namespace Radiergummi\Rls\Context;

use Closure;
use Illuminate\Log\Context\Repository;

class RlsManager
{
    private const KEY = 'rls';

    private ?Closure $sync = null;

    public function __construct(private readonly Repository $context) {}

    public function setSyncCallback(?Closure $sync): void
    {
        $this->sync = $sync;
    }

    public function push(RlsContext $context): void
    {
        $this->context->push(self::KEY, $context);
        $this->afterChange();
    }

    public function pop(): void
    {
        if ($this->hasContext()) {
            $this->context->pop(self::KEY);
        }
        $this->afterChange();
    }

    public function current(): ?RlsContext
    {
        $stack = $this->stack();

        return $stack === [] ? null : $stack[array_key_last($stack)];
    }

    public function hasContext(): bool
    {
        return $this->stack() !== [];
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
        $this->context->forget(self::KEY);
        $this->afterChange();
    }

    /**
     * Strip bypass contexts before the context is serialized into a queued
     * job, so a job dispatched inside a bypass scope never inherits bypass.
     */
    public static function stripBypassOnDehydrate(Repository $context): void
    {
        $stack = $context->get(self::KEY, []);

        if ($stack === []) {
            return;
        }

        $filtered = array_values(array_filter(
            $stack,
            fn (RlsContext $c) => ! $c->isBypass(),
        ));

        if ($filtered === []) {
            $context->forget(self::KEY);
        } else {
            $context->add(self::KEY, $filtered);
        }
    }

    /** @return list<RlsContext> */
    private function stack(): array
    {
        return $this->context->get(self::KEY, []);
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
