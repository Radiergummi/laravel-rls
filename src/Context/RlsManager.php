<?php

namespace Radiergummi\Rls\Context;

use Closure;
use Illuminate\Log\Context\Repository;

class RlsManager
{
    private const KEY = 'rls';

    private ?Closure $sync = null;

    private ?Closure $bypassHandler = null;

    private ?Closure $resolver = null;

    public function __construct(private readonly Repository $context) {}

    /**
     * Register the app's identity -> context mapping. Called from the
     * publishable RlsServiceProvider.
     */
    public function resolveContextUsing(Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    /**
     * Establish context from a freshly authenticated user, using the app's
     * resolver. A no-op if no resolver is registered or it yields nothing.
     */
    public function establishFromUser(mixed $user): void
    {
        if ($this->resolver === null) {
            return;
        }

        $context = ($this->resolver)($user);

        if (is_array($context) && $context !== []) {
            $this->push(RlsContext::make($context));
        }
    }

    public function setSyncCallback(?Closure $sync): void
    {
        $this->sync = $sync;
    }

    /**
     * Override how bypass scopes (system()/withoutRls()) are handled. When
     * unset, bypass pushes a bypass context (owner mode, GUC-driven). In
     * restricted mode the provider installs a handler that routes the callback
     * to the admin connection instead.
     */
    public function setBypassHandler(?Closure $handler): void
    {
        $this->bypassHandler = $handler;
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
        if ($this->bypassHandler !== null) {
            return ($this->bypassHandler)($reason, $callback);
        }

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
