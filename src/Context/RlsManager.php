<?php

namespace Radiergummi\LaravelRls\Context;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Context\Repository;
use Radiergummi\LaravelRls\Events\RlsBypassed;

class RlsManager
{
    private const KEY = 'rls';

    private ?Closure $sync = null;

    private ?Closure $bypassHandler = null;

    private ?Closure $resolver = null;

    private ?ContextSchema $schema = null;

    public function __construct(
        private readonly Repository $context,
        private readonly ?Dispatcher $events = null,
    ) {}

    /**
     * Declare the app's context dimensions (opt-in sugar). Enables typed PHP
     * accessors (Rls::tenantId()) and typed SQL helper generation.
     */
    public function defineContext(Closure $callback): void
    {
        $schema = new ContextSchema();
        $callback($schema);
        $this->schema = $schema;
    }

    public function schema(): ?ContextSchema
    {
        return $this->schema;
    }

    /** Typed accessors for declared dimensions, e.g. Rls::tenantId(). */
    public function __call(string $method, array $arguments): mixed
    {
        $key = \Illuminate\Support\Str::snake($method);

        if ($this->schema?->has($key)) {
            return $this->get($key);
        }

        throw new \BadMethodCallException(
            "Method {$method}() is not a declared RLS context dimension.",
        );
    }

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
        $this->validate($context->values());
        $this->context->push(self::KEY, $context);
        $this->afterChange();
    }

    /**
     * Validate context values against the declared schema before they leave PHP.
     * A malformed value (e.g. a non-UUID for a uuid dimension) would otherwise
     * reach Postgres and throw on every query — a cluster-wide failure.
     */
    private function validate(array $values): void
    {
        if ($this->schema === null) {
            return;
        }

        foreach ($values as $key => $value) {
            if (! $this->schema->matches($key, $value)) {
                throw \Radiergummi\LaravelRls\Exceptions\InvalidContextValue::forDimension(
                    $key,
                    $this->schema->dimensions()[$key],
                    $value,
                );
            }
        }
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
        $this->events?->dispatch(new RlsBypassed($reason));

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
     * Runtime leak canary. On long-lived workers (queue, Octane) a context that
     * was never popped would silently carry over into the next unit of work — a
     * cross-tenant hazard. Called at each request/job boundary: if the stack is
     * not empty it clears the stale context and surfaces it per the configured
     * mode ('log' | 'throw' | 'off').
     */
    public function checkForLeak(string $boundary): void
    {
        $mode = \config('rls.leak_canary', 'log');

        if ($mode === 'off' || ! $this->hasContext()) {
            return;
        }

        $dimensions = array_keys($this->context());
        $this->forget();

        if ($mode === 'throw') {
            throw \Radiergummi\LaravelRls\Exceptions\RlsContextLeaked::at($boundary, $dimensions);
        }

        \Illuminate\Support\Facades\Log::critical(
            "RLS context leaked into a new {$boundary} and was cleared.",
            ['boundary' => $boundary, 'dimensions' => $dimensions],
        );
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
