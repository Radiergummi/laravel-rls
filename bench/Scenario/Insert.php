<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Scenario;

use Illuminate\Support\Str;
use Radiergummi\LaravelRls\Bench\TableSet;
use Radiergummi\LaravelRls\Bench\Variant;

final class Insert extends Scenario
{
    public function name(): string
    {
        return 'insert';
    }

    public function run(Variant $variant): void
    {
        // A write has no WHERE, so floor and control coincide (plain insert). Treatment inserts
        // through the isolated table, exercising WITH CHECK + context injection.
        match ($variant) {
            Variant::Floor => $this->db()->table(TableSet::FLOOR)->insert($this->row()),
            Variant::Control => $this->db()->table(TableSet::CONTROL)->insert($this->row()),
            Variant::Treatment => $this->rls()->isolateTo(
                ['tenant_id' => $this->tables->probeTenantId],
                fn() => $this->db()->table(TableSet::TREATMENT)->insert($this->row()),
            ),
        };
    }

    /**
     * A fresh probe-tenant row (unique id per call).
     *
     * @return array{id:string,tenant_id:string,n:int,payload:string}
     */
    private function row(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tables->probeTenantId,
            'n' => 0,
            'payload' => 'x',
        ];
    }
}
