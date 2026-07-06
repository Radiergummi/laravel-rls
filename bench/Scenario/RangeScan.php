<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Scenario;

use Radiergummi\LaravelRls\Bench\TableSet;
use Radiergummi\LaravelRls\Bench\Variant;

final class RangeScan extends Scenario
{
    public function name(): string
    {
        return 'range_scan';
    }

    public function run(Variant $variant): void
    {
        [$lo, $hi] = [$this->tables->probeRangeLo, $this->tables->probeRangeHi];

        match ($variant) {
            Variant::Floor => $this->db()->select(
                'select * from ' . TableSet::FLOOR . ' where n between ? and ?',
                [$lo, $hi],
            ),
            Variant::Control => $this->db()->select(
                'select * from ' . TableSet::CONTROL . ' where n between ? and ? and tenant_id = ?',
                [$lo, $hi, $this->tables->probeTenantId],
            ),
            Variant::Treatment => $this->rls()->isolateTo(
                ['tenant_id' => $this->tables->probeTenantId],
                fn() => $this->db()->select(
                    'select * from ' . TableSet::TREATMENT . ' where n between ? and ?',
                    [$lo, $hi],
                ),
            ),
        };
    }

    public function explainTarget(): ?array
    {
        return [
            'sql' => 'select * from ' . TableSet::TREATMENT . ' where n between ? and ?',
            'bindings' => [$this->tables->probeRangeLo, $this->tables->probeRangeHi],
            'tenant' => $this->tables->probeTenantId,
        ];
    }
}
