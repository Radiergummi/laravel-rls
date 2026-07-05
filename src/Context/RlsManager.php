<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Context;

use BadMethodCallException;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Radiergummi\LaravelRls\Events\RlsBypassed;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Exceptions\RlsContextLeaked;
use RuntimeException;

use function assert;
use function config;
use function is_array;

/**
 * RLS Manager
 *
 * @template TUser = mixed
 */
class RlsManager
{
    private const KEY = 'rls';

    /** @var null|Closure(): void */
    private ?Closure $sync = null;

    /** @var null|Closure(string, Closure(): mixed): mixed */
    private ?Closure $bypassHandler = null;

    /** @var null|Closure(TUser): mixed */
    private ?Closure $resolver = null;

    private ?ContextSchema $schema = null;

    public function __construct(
        private readonly Repository $context,
        private readonly ?Dispatcher $events = null,
    ) {}

    /**
     * Strip bypass contexts before the context is serialized into a queued job, so a job dispatched
     * inside a bypass scope never inherits bypass.
     */
    public static function stripBypassOnDehydrate(Repository $context): void
    {
        /** @var list<RlsContext> $stack */
        $stack = $context->get(self::KEY, []);
        assert(is_array($stack));

        if ($stack === []) {
            return;
        }

        $filtered = array_values(
            array_filter(
                $stack,
                static fn(RlsContext $context): bool => !$context->isBypass(),
            ),
        );

        if ($filtered === []) {
            $context->forget(self::KEY);
        } else {
            $context->add(self::KEY, $filtered);
        }
    }

    /**
     * Declare the app's isolation keys (opt in sugar).
     *
     * Enables typed PHP accessors (Rls::tenantId()) and typed SQL helper generation.
     *
     * @param Closure(ContextSchema): mixed $callback
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

    /**
     * Typed accessors for declared isolation keys, e.g., `Rls::tenantId()`.
     *
     * @param list<mixed> $arguments
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $arguments): mixed
    {
        $key = Str::snake($method);

        if ($this->schema?->has($key)) {
            return $this->get($key);
        }

        throw new BadMethodCallException(
            "Method {$method}() is not a declared RLS isolation key.",
        );
    }

    public function get(string $key): mixed
    {
        return $this->current()?->get($key);
    }

    public function current(): ?RlsContext
    {
        $stack = $this->stack();

        return $stack === [] ? null : $stack[array_key_last($stack)];
    }

    /**
     * @return list<RlsContext>
     */
    private function stack(): array
    {
        /** @var list<RlsContext> */
        return $this->context->get(self::KEY, []);
    }

    /**
     * Register the app's identity -> context mapping.
     *
     * Called from the publishable RlsServiceProvider.
     *
     * @param Closure(TUser): mixed $resolver
     */
    public function resolveContextUsing(Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    /**
     * Establish context from a freshly authenticated user, using the app's resolver.
     *
     * A no-op if no resolver is registered, or it yields nothing.
     *
     * @param TUser $user
     *
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    public function establishFromUser(mixed $user): void
    {
        if ($this->resolver === null) {
            return;
        }

        $context = ($this->resolver)($user);

        if (is_array($context) && $context !== []) {
            // @var array<string, scalar|null> $context
            $this->push(RlsContext::make($context));
        }
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    public function push(RlsContext $context): void
    {
        $this->validate($context->values());
        $this->context->push(self::KEY, $context);
        $this->afterChange();
    }

    /**
     * Validate context values against the declared schema before they leave PHP.
     *
     * A malformed value (e.g., a non-UUID for an uuid isolation key) would otherwise reach Postgres and
     * throw on every query — a cluster-wide failure.
     *
     * @param array<string, mixed> $values
     *
     * @throws InvalidContextValue
     */
    private function validate(array $values): void
    {
        if ($this->schema === null) {
            return;
        }

        foreach ($values as $key => $value) {
            // null is the fail-closed sentinel (a context-less user, a not-yet-set isolation key):
            // it serializes to an empty GUC that rls.context() reads as NULL, yielding zero rows —
            // safe, not malformed. Validating it would 500 the Authenticated listener for every
            // such user.
            if ($value === null) {
                continue;
            }

            if (!$this->schema->matches($key, $value)) {
                throw InvalidContextValue::forIsolationKey(
                    $key,
                    $this->schema->isolationKeys()[$key],
                    $value,
                );
            }
        }
    }

    private function afterChange(): void
    {
        if ($this->sync !== null) {
            ($this->sync)();
        }
    }

    /**
     * @param null|Closure(): void $sync
     */
    public function setSyncCallback(?Closure $sync): void
    {
        $this->sync = $sync;
    }

    /**
     * Override how bypass scopes (system()/withoutIsolation()) are handled.
     *
     * When unset, bypass pushes a bypass context (owner mode, GUC-driven). In restricted mode the
     * provider installs a handler that routes the callback to the admin connection instead.
     *
     * @param null|Closure(string, Closure(): mixed): mixed $handler
     */
    public function setBypassHandler(?Closure $handler): void
    {
        $this->bypassHandler = $handler;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->current()?->values() ?? [];
    }

    /**
     * @param null|scalar $value
     *
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    public function set(string $key, mixed $value): void
    {
        $next = ($this->current() ?? RlsContext::make([]))->with([$key => $value]);

        // Validate before mutating the stack: if the value is invalid, push() would throw *after*
        // the pop, silently dropping the current frame (and exposing whatever context sits
        // beneath it).
        $this->validate($next->values());

        $this->pop();
        $this->push($next);
    }

    /**
     * @throws RuntimeException
     */
    public function pop(): void
    {
        if ($this->hasContext()) {
            $this->context->pop(self::KEY);
        }
        $this->afterChange();
    }

    public function hasContext(): bool
    {
        return $this->stack() !== [];
    }

    /**
     * @template T = mixed
     *
     * @param array<string, null|scalar> $context
     * @param null|Closure(): T          $callback
     *
     * @return ($callback is null ? null : T)
     *
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    public function isolateTo(array $context, ?Closure $callback = null): mixed
    {
        return $this->enter(RlsContext::make($context), $callback);
    }

    /**
     * @template T
     *
     * @param null|Closure(): T $callback
     *
     * @return ($callback is null ? null : T)
     *
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
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

    /**
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     *
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    public function system(string $reason, Closure $callback): mixed
    {
        return $this->withoutIsolation($reason, $callback);
    }

    /**
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     *
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    public function withoutIsolation(string $reason, Closure $callback): mixed
    {
        $this->events?->dispatch(new RlsBypassed($reason));

        if ($this->bypassHandler !== null) {
            return ($this->bypassHandler)($reason, $callback);
        }

        return $this->enter(RlsContext::bypass($reason), $callback);
    }

    /**
     * Runtime leak canary. On long-lived workers (queue, Octane) a context that was never popped
     * would silently carry over into the next unit of work — a cross-context hazard. Called at each
     * request/job boundary: if the stack is not empty, it clears the stale context and surfaces it
     * per the configured mode ('log' | 'throw' | 'off').
     *
     * @throws RlsContextLeaked
     */
    public function checkForLeak(string $boundary): void
    {
        $mode = config('rls.leak_canary', 'log');

        if ($mode === 'off' || !$this->hasContext()) {
            return;
        }

        // Collect the isolation keys across the *whole* stack, not just the current frame: a nested
        // leak (multiple unpopped frames) has forget() to clear them all, so the record must name
        // every leaked isolation key, not only the top.
        $isolationKeys = [];

        foreach ($this->stack() as $frame) {
            $isolationKeys = [...$isolationKeys, ...array_keys($frame->values())];
        }
        $isolationKeys = array_values(array_unique($isolationKeys));
        $this->forget();

        if ($mode === 'throw') {
            throw RlsContextLeaked::at($boundary, $isolationKeys);
        }

        Log::critical(
            "RLS context leaked into a new {$boundary} and was cleared.",
            ['boundary' => $boundary, 'isolation_keys' => $isolationKeys],
        );
    }

    public function forget(): void
    {
        $this->context->forget(self::KEY);
        $this->afterChange();
    }
}
