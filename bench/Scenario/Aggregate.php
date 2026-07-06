<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Scenario;

use Radiergummi\LaravelRls\Bench\TableSet;
use Radiergummi\LaravelRls\Bench\Variant;

final class Aggregate extends Scenario
{
    public function name(): string
    {
        return 'aggregate';
    }

    public function run(Variant $variant): void
    {
        match ($variant) {
            Variant::Floor => $this->db()->select('select count(*) from ' . TableSet::FLOOR),
            Variant::Control => $this->db()->select(
                'select count(*) from ' . TableSet::CONTROL . ' where tenant_id = ?',
                [$this->tables->probeTenantId],
            ),
            Variant::Treatment => $this->rls()->isolateTo(
                ['tenant_id' => $this->tables->probeTenantId],
                fn() => $this->db()->select('select count(*) from ' . TableSet::TREATMENT),
            ),
        };
    }

    public function explainTarget(): ?array
    {
        return [
            'sql' => 'select count(*) from ' . TableSet::TREATMENT,
            'bindings' => [],
            'tenant' => $this->tables->probeTenantId,
        ];
    }
}
