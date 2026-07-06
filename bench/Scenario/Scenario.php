<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Scenario;

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

    protected function db(): Connection
    {
        return $this->app->make('db')->connection();
    }

    protected function rls(): RlsManager
    {
        return $this->app->make('rls');
    }
}
