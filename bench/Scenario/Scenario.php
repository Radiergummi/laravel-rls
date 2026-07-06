<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Scenario;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Radiergummi\LaravelRls\Bench\TableSet;
use Radiergummi\LaravelRls\Bench\Variant;
use Radiergummi\LaravelRls\Context\RlsManager;

abstract class Scenario
{
    public function __construct(
        protected readonly Application $app,
        protected readonly TableSet $tables,
    ) {}

    abstract public function name(): string;

    abstract public function run(Variant $variant): void;

    /**
     * The treatment read for the DB-side EXPLAIN probe, or null for write scenarios.
     *
     * @return null|array{sql:string,bindings:list<mixed>,tenant:string}
     */
    public function explainTarget(): ?array
    {
        return null;
    }

    /**
     * Shape a treatment-read EXPLAIN target. The probe always runs in the probe-tenant context.
     *
     * @param list<mixed> $bindings
     *
     * @return array{sql:string,bindings:list<mixed>,tenant:string}
     */
    protected function treatmentExplain(string $sql, array $bindings): array
    {
        return ['sql' => $sql, 'bindings' => $bindings, 'tenant' => $this->tables->probeTenantId];
    }

    protected function db(): Connection
    {
        return $this->app->make('db')->connection();
    }

    /**
     * @throws BindingResolutionException
     */
    protected function rls(): RlsManager
    {
        return $this->app->make(RlsManager::class);
    }
}
