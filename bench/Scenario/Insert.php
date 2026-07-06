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
        $row = static fn(): array => [
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'n' => 0,
            'payload' => 'x',
        ];

        match ($variant) {
            Variant::Floor => $this->db()->table(TableSet::FLOOR)->insert(
                ['tenant_id' => $this->tables->probeTenantId] + $row(),
            ),
            Variant::Control => $this->db()->table(TableSet::CONTROL)->insert(
                ['tenant_id' => $this->tables->probeTenantId] + $row(),
            ),
            Variant::Treatment => $this->rls()->isolateTo(
                ['tenant_id' => $this->tables->probeTenantId],
                fn() => $this->db()->table(TableSet::TREATMENT)->insert(
                    ['tenant_id' => $this->tables->probeTenantId] + $row(),
                ),
            ),
        };
    }
}
