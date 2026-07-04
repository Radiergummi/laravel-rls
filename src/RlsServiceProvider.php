<?php

namespace Radiergummi\LaravelRls;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use Radiergummi\LaravelRls\Context\RlsManager;
use Radiergummi\LaravelRls\Database\RlsPostgresConnection;
use Radiergummi\LaravelRls\Exceptions\ResolverCollision;

class RlsServiceProvider extends ServiceProvider
{
    /** The pgsql resolver we registered, tracked so we recognise our own. */
    private static ?Closure $ownResolver = null;
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rls.php', 'rls');

        $this->app->singleton('rls', fn ($app) => new RlsManager(
            $app->make(\Illuminate\Log\Context\Repository::class),
            $app->make('events'),
        ));
        $this->app->alias('rls', RlsManager::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Radiergummi\LaravelRls\Console\CheckCommand::class,
                \Radiergummi\LaravelRls\Console\AuditCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        $this->registerConnectionResolver();

        $manager = $this->app->make('rls');

        $manager->setSyncCallback(function () {
            $connection = $this->app->make('db')->connection();

            // Capability check (not instanceof) so composed connections built
            // on other pgsql packages (e.g. tpetry) are recognised too.
            // applyRlsContext() self-guards per strategy (no-op for the
            // transaction strategy outside a transaction).
            if (method_exists($connection, 'applyRlsContext')) {
                $connection->applyRlsContext();
            }
        });

        \Radiergummi\LaravelRls\Schema\RlsSchemaMacros::register();

        \Illuminate\Support\Facades\Context::dehydrating(
            fn ($context) => RlsManager::stripBypassOnDehydrate($context),
        );

        $this->app['events']->listen(
            \Illuminate\Auth\Events\Authenticated::class,
            fn ($event) => $manager->establishFromUser($event->user),
        );

        // Bypass observability: every withoutRls()/system() is logged with its
        // reason, so RLS bypass stays visible in production logs.
        $this->app['events']->listen(
            \Radiergummi\LaravelRls\Events\RlsBypassed::class,
            fn ($event) => \Illuminate\Support\Facades\Log::notice(
                "RLS bypassed: {$event->reason}",
                ['reason' => $event->reason],
            ),
        );

        // Worker boundary handling: on long-lived workers a context that was
        // never popped would carry into the next job/request (leak canary), and
        // under the session strategy a session GUC persists on the pooled
        // connection even when the scoped context stack is reset (flush). Both
        // run at each boundary. Octane is optional, so guard its event class.
        //
        // For the queue we hook `Looping` (fired between jobs in the daemon
        // loop, before Laravel hydrates the next job's context) rather than
        // `JobProcessing` (which fires *after* hydration and would flag every
        // job's own context as a leak). `--once` runs a fresh process, so it
        // needs no check. The listener must return null, not false, or it would
        // veto the worker's `until(Looping)` loop.
        $onBoundary = function (string $boundary) use ($manager): void {
            $manager->checkForLeak($boundary);
            $this->flushSessionContext();
        };

        $this->app['events']->listen(
            \Illuminate\Queue\Events\Looping::class,
            function () use ($onBoundary): void {
                $onBoundary('job');
            },
        );

        if (class_exists(\Laravel\Octane\Events\RequestReceived::class)) {
            $this->app['events']->listen(
                \Laravel\Octane\Events\RequestReceived::class,
                function () use ($onBoundary): void {
                    $onBoundary('request');
                },
            );
        }

        if (config('rls.role_model') === 'restricted') {
            $manager->setBypassHandler(function (string $reason, \Closure $callback) {
                $admin = config('rls.admin_connection');

                if ($admin === null) {
                    throw \Radiergummi\LaravelRls\Exceptions\AdminConnectionRequired::forReason($reason);
                }

                $db = $this->app->make('db');
                $previous = $db->getDefaultConnection();
                $db->setDefaultConnection($admin);

                try {
                    return $callback();
                } finally {
                    $db->setDefaultConnection($previous);
                }
            });
        }
    }

    /**
     * Register our pgsql connection resolver, refusing to silently clobber
     * another package's resolver. Registered from boot() (not register()) so we
     * win over packages that register in boot(); to compose with such a package,
     * point rls.connection_class at a class extending theirs.
     *
     * @internal
     */
    public function registerConnectionResolver(): void
    {
        $configured = config('rls.connection_class', RlsPostgresConnection::class);

        if (self::detectResolverCollision(Connection::getResolver('pgsql'), self::$ownResolver, $configured)) {
            throw ResolverCollision::forDriver('pgsql');
        }

        self::$ownResolver = function ($pdo, $database, $prefix, $config) {
            $class = config('rls.connection_class', RlsPostgresConnection::class);

            return new $class($pdo, $database, $prefix, $config);
        };

        Connection::resolverFor('pgsql', self::$ownResolver);
    }

    /**
     * Blank the session GUCs on every resolved connection at a worker boundary,
     * so the session strategy cannot leak one request/job's context into the
     * next on a pooled connection. A no-op under the transaction strategy.
     */
    private function flushSessionContext(): void
    {
        if (config('rls.strategy', 'transaction') !== 'session') {
            return;
        }

        foreach ($this->app->make('db')->getConnections() as $connection) {
            if (method_exists($connection, 'resetSessionContext')) {
                $connection->resetSessionContext();
            }
        }
    }

    /**
     * A collision is a foreign resolver already in place (not our own, tracked
     * by identity) while connection_class is still the default — meaning we
     * would overwrite the other package rather than compose with it.
     *
     * @internal
     */
    public static function detectResolverCollision(?Closure $existing, ?Closure $own, string $configured): bool
    {
        return $existing !== null
            && $existing !== $own
            && $configured === RlsPostgresConnection::class;
    }
}
