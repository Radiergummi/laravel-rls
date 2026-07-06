<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls;

use Closure;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Log\Context\Repository;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Radiergummi\LaravelRls\Console\AuditCommand;
use Radiergummi\LaravelRls\Console\CheckCommand;
use Radiergummi\LaravelRls\Console\InstallCommand;
use Radiergummi\LaravelRls\Console\SyncCommand;
use Radiergummi\LaravelRls\Context\RlsManager;
use Radiergummi\LaravelRls\Database\RlsPostgresConnection;
use Radiergummi\LaravelRls\Events\RlsBypassed;
use Radiergummi\LaravelRls\Exceptions\AdminConnectionRequired;
use Radiergummi\LaravelRls\Exceptions\ResolverCollision;
use Radiergummi\LaravelRls\Schema\RlsSchemaMacros;

use function assert;
use function is_string;

class RlsServiceProvider extends ServiceProvider
{
    /**
     * The pgsql resolver we registered, tracked, so we recognize our own.
     *
     * @var null|Closure(mixed, string, string, array<string, mixed>): mixed
     */
    private static ?Closure $ownResolver = null;

    /**
     * @throws BindingResolutionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ResolverCollision
     */
    public function boot(): void
    {
        $this->registerConnectionResolver();

        $manager = $this->app->make(RlsManager::class);

        $manager->setSyncCallback(function () {
            $connection = $this->app->make(DatabaseManager::class)->connection();

            // Capability check (not instanceof) so composed connections built on other pgsql
            // packages (e.g., tpetry) are recognized too. applyRlsContext() self-guards per
            // strategy (no-op for the transaction strategy outside a transaction).
            if (method_exists($connection, 'applyRlsContext')) {
                $connection->applyRlsContext();
            }
        });

        RlsSchemaMacros::register();

        $this->app->get(Dispatcher::class)->listen(
            Authenticated::class,
            fn(Authenticated $event) => $manager->establishFromUser($event->user),
        );

        // Bypass observability: every withoutIsolation()/system() is logged with its reason, so RLS
        // bypass stays visible in production logs.
        $this->app->get(Dispatcher::class)->listen(
            RlsBypassed::class,
            fn(RlsBypassed $event)
                => Log::notice(
                    "RLS bypassed: {$event->reason}",
                    ['reason' => $event->reason],
                ),
        );

        // Worker boundary handling: on long-lived workers a context that was never popped would
        // carry into the next job/request (leak canary), and under the session strategy a session
        // GUC persists on the pooled connection even when the scoped context stack is reset
        // (flush). Both run at each boundary. Octane is optional, so guard its event class.
        //
        // For the queue we hook `Looping` (fired between jobs in the daemon loop, before Laravel
        // hydrates the next job's context) rather than `JobProcessing` (which fires *after*
        // hydration and would flag every job's own context as a leak). `--once` runs a fresh
        // process, so it needs no check. The listener must return null, not false, or it would veto
        // the worker's `until(Looping)` loop.
        $onBoundary = function (string $boundary) use ($manager): void {
            $manager->checkForLeak($boundary);
            $this->flushSessionContext();
        };

        $this->app->get(Dispatcher::class)->listen(
            Looping::class,
            function () use ($onBoundary): void {
                $onBoundary('job');
            },
        );

        if (class_exists(RequestReceived::class)) {
            $this->app->get(Dispatcher::class)->listen(
                RequestReceived::class,
                function () use ($onBoundary): void {
                    $onBoundary('request');
                },
            );
        }

        // Bypass routes to a privileged admin connection (a BYPASSRLS role) in *both* role models:
        // the isolation predicate is equality-only for index performance, so there is no in-band
        // bypass to fall back on. The handler is installed unconditionally; it hard-fails when no
        // admin_connection is configured.
        $manager->setBypassHandler(function (string $reason, Closure $callback) {
            $admin = config('rls.admin_connection');
            assert(is_string($admin) || $admin === null);

            if ($admin === null) {
                throw AdminConnectionRequired::forReason($reason);
            }

            $database = $this->app->make(DatabaseManager::class);
            $previous = $database->getDefaultConnection();
            $database->setDefaultConnection($admin);

            try {
                return $callback();
            } finally {
                $database->setDefaultConnection($previous);
            }
        });
    }

    /**
     * Register our pgsql connection resolver, refusing to silently clobber another package's
     * resolver. Registered from boot() (not `register()`), so we win over packages that register in
     * `boot()`; to compose with such a package, point `rls.connection_class` at a class
     * extending theirs.
     *
     * @throws ResolverCollision
     *
     * @internal
     */
    public function registerConnectionResolver(): void
    {
        $configured = config('rls.connection_class', RlsPostgresConnection::class);
        assert(is_string($configured));

        if (
            self::detectResolverCollision(
                Connection::getResolver('pgsql'),
                self::$ownResolver,
                $configured,
            )
        ) {
            throw ResolverCollision::forDriver('pgsql');
        }

        self::$ownResolver = static function ($pdo, $database, $prefix, $config) {
            $class = config('rls.connection_class', RlsPostgresConnection::class);

            return new $class($pdo, $database, $prefix, $config);
        };

        Connection::resolverFor('pgsql', self::$ownResolver);
    }

    /**
     * A collision is a foreign resolver already in place (not our own, tracked by identity) while
     * connection_class is still the default — meaning we would overwrite the other package rather
     * than compose with it.
     *
     * @param null|Closure(mixed, string, string, array<string, mixed>): mixed $existing
     * @param null|Closure(mixed, string, string, array<string, mixed>): mixed $own
     *
     * @internal
     */
    public static function detectResolverCollision(
        ?Closure $existing,
        ?Closure $own,
        string $configured,
    ): bool {
        return $existing !== null
            && $existing !== $own
            && $configured === RlsPostgresConnection::class;
    }

    /**
     * @throws LogicException
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rls.php', 'rls');

        $this->app->singleton(RlsManager::class, fn(Application $app) => new RlsManager(
            $app->make(Repository::class),
            $app->make('events'),
        ));
        $this->app->alias(RlsManager::class, 'rls');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                SyncCommand::class,
                CheckCommand::class,
                AuditCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/rls.php' => config_path('rls.php'),
            ], 'rls-config');

            $this->publishesMigrations([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'rls-migrations');

            $this->publishes([
                __DIR__ . '/../stubs/rls-provider.stub' => app_path('Providers/RlsServiceProvider.php'),
            ], 'rls-provider');
        }
    }

    /**
     * Blank the session GUCs on every resolved connection at a worker boundary, so the session
     * strategy cannot leak one request/job's context into the next on a pooled connection.
     * A no-op under the transaction strategy.
     *
     * @throws BindingResolutionException
     */
    private function flushSessionContext(): void
    {
        if (config('rls.strategy', 'transaction') !== 'session') {
            return;
        }

        foreach ($this->app->make(DatabaseManager::class)->getConnections() as $connection) {
            if (method_exists($connection, 'resetSessionContext')) {
                $connection->resetSessionContext();
            }
        }
    }
}
