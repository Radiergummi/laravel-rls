<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Context;

use BadMethodCallException;
use Closure;
use Illuminate\Container\Attributes\Log;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Radiergummi\LaravelRls\Events\RlsBypassed;
use Radiergummi\LaravelRls\Exceptions\AdminConnectionRequired;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Exceptions\RlsContextLeaked;
use Radiergummi\LaravelRls\RlsServiceProvider;
use RuntimeException;

use function config;
use function is_array;

/**
 * RLS Manager
 *
 * @template TUser of Authenticatable = Authenticatable
 */
#[Singleton]
class RlsManager
{
    private const KEY = 'rls';

    /**
     * @var null|Closure(): void
     */
    private ?Closure $sync = null;

    /**
     * A callback to handle RLS bypass.
     *
     * @var null|Closure(non-empty-string, Closure(): mixed): mixed
     */
    private ?Closure $bypassHandler = null;

    /**
     * @var null|Closure(TUser): mixed
     */
    private ?Closure $resolver = null;

    private ?ContextSchema $schema = null;

    /**
     * Set for the duration of a withoutIsolation()/system() callback. Bypass no longer lives on the
     * context stack (it routes to the admin connection instead), so the fail-loud guard reads this
     * in-flight flag to recognize it is inside a bypass scope and stand down.
     */
    private bool $bypassing = false;

    public function __construct(
        private readonly Container $container,
        #[Log(RlsServiceProvider::RLS_LOG_CHANNEL)]
        private readonly LoggerInterface $logger,
        private readonly ?Dispatcher $events = null,
    ) {}

    /**
     * The live Context repository. Resolved on each access rather than captured,
     * because it is a *scoped* binding: a queue daemon and Octane reset scoped
     * instances between jobs/requests, so a reference held by this singleton
     * would go stale and read a repository the framework no longer hydrates.
     */
    private function repository(): Repository
    {
        return $this->container->make(Repository::class);
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
        return $this->repository()->get(self::KEY, []);
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
    public function establishFromUser(Authenticatable $user): void
    {
        if ($this->resolver === null) {
            return;
        }

        /** @var null|array<string, null|scalar> $context */
        $context = ($this->resolver)($user);

        if (is_array($context) && $context !== []) {
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
        $this->repository()->push(self::KEY, $context);
        $this->afterChange();
    }

    /**
     * Validate context values against the declared schema before they leave PHP.
     *
     * A malformed value (e.g., a non-UUID for an uuid isolation key) would otherwise reach Postgres
     * and throw on every query — a cluster-wide failure.
     *
     * @param array<string, null|scalar> $values
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
     * Install how bypass scopes (system()/withoutIsolation()) are handled: the handler runs the
     * callback against a privileged admin connection. The provider installs it in both role models;
     * when unset, withoutIsolation() hard-fails with AdminConnectionRequired.
     *
     * @param null|callable(non-empty-string, Closure(): mixed): mixed $handler
     */
    public function setBypassHandler(?callable $handler): void
    {
        $this->bypassHandler = $handler ? $handler(...) : null;
    }

    /**
     * @return array<string, null|scalar>
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
            $this->repository()->pop(self::KEY);
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
     * @param non-empty-string $reason
     * @param Closure(): T     $callback
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
     * @param non-empty-string $reason
     * @param Closure(): T     $callback
     *
     * @return T
     *
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    public function withoutIsolation(string $reason, Closure $callback): mixed
    {
        $this->events?->dispatch(new RlsBypassed($reason));

        // Bypass always routes to a privileged admin connection (a BYPASSRLS role). There is no
        // in-band bypass: the read predicate is equality-only for index performance, and under
        // FORCE there is no way to disable RLS within the session. Without a handler (unbooted
        // manager, or no admin_connection configured) there is nothing to route to, so we bail.
        if ($this->bypassHandler === null) {
            throw AdminConnectionRequired::forReason($reason);
        }

        // The in-flight flag's lifetime is exactly the handler call, so own it here — no handler can
        // get the paired-finally invariant wrong, and the fail-loud guard reads a single writer.
        $this->bypassing = true;

        try {
            return ($this->bypassHandler)($reason, $callback);
        } finally {
            $this->bypassing = false;
        }
    }

    public function isBypassing(): bool
    {
        return $this->bypassing;
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

        $this->logger->critical(
            "RLS context leaked into a new {$boundary} and was cleared.",
            ['boundary' => $boundary, 'isolation_keys' => $isolationKeys],
        );
    }

    public function forget(): void
    {
        $this->repository()->forget(self::KEY);
        $this->afterChange();
    }
}
