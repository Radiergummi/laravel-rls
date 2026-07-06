<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature\Bench;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Boot;
use Radiergummi\LaravelRls\Bench\Endpoint;
use Radiergummi\LaravelRls\Bench\EndpointConfig;
use Radiergummi\LaravelRls\Bench\Schema;
use Radiergummi\LaravelRls\Context\RlsManager;
use Radiergummi\LaravelRls\Database\RlsPostgresConnection;
use Throwable;

#[TestDox('Bench Endpoint')]
class EndpointTest extends TestCase
{
    #[Test]
    #[TestDox('Direct configs scope correctly and run without error')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function direct_configs_scope_correctly_and_run(): void
    {
        $app = Boot::app();
        $schema = new Schema($app);
        $tables = $schema->seed('1k'); // scale-independent correctness; fast

        try {
            foreach (array_slice(EndpointConfig::matrix(), 0, 3) as $cfg) {
                config(['database.default' => $cfg->connectionName, 'rls.strategy' => $cfg->strategy]);
                $endpoint = new Endpoint($app, $tables, 5);

                try {
                    $this->assertTrue($endpoint->treatmentIsCorrect($cfg), $cfg->label);
                    $endpoint->run($cfg, 'control');
                    $endpoint->run($cfg, 'treatment');
                } finally {
                    $app->make(RlsManager::class)->forget();
                    $connection = DB::connection($cfg->connectionName);

                    if ($connection instanceof RlsPostgresConnection) {
                        $connection->resetSessionContext(); // while strategy is still 'session'
                    }
                    config(['database.default' => 'pgsql', 'rls.strategy' => 'transaction']);
                }
            }
        } finally {
            $schema->drop();
        }
    }

    #[Test]
    #[TestDox('pgbouncer-session is unsafe by construction')]
    public function pgbouncer_session_is_unsafe_by_construction(): void
    {
        $config = EndpointConfig::matrix()[5];

        $this->assertTrue($config->unsafe);
        $this->assertSame('session', $config->strategy);
        $this->assertSame('pgsql_pgbouncer', $config->connectionName);
    }

    #[Test]
    #[TestDox('PgBouncer transaction configs scope correctly when reachable')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function pgbouncer_transaction_configs_scope_correctly(): void
    {
        $app = Boot::app();

        try {
            DB::connection('pgsql_pgbouncer')->getPdo();
        } catch (Throwable $exception) {
            $this->markTestSkipped("PgBouncer not reachable on 127.0.0.1:6432: {$exception->getMessage()}");
        }

        $schema = new Schema($app);
        $tables = $schema->seed('1k');

        try {
            foreach (array_slice(EndpointConfig::matrix(), 3, 2) as $cfg) { // configs 4, 5
                config(['database.default' => $cfg->connectionName, 'rls.strategy' => $cfg->strategy]);
                $endpoint = new Endpoint($app, $tables, 5);

                try {
                    $this->assertTrue($endpoint->treatmentIsCorrect($cfg), $cfg->label);
                    $endpoint->run($cfg, 'treatment');
                } finally {
                    $app->make(RlsManager::class)->forget();
                    config(['database.default' => 'pgsql', 'rls.strategy' => 'transaction']);
                }
            }
        } finally {
            $schema->drop();
        }
    }
}
