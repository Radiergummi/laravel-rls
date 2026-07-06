<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Scenario;

use Radiergummi\LaravelRls\Bench\TableSet;
use Radiergummi\LaravelRls\Bench\Variant;

final class PointSelect extends Scenario
{
    public function name(): string
    {
        return 'point_select';
    }

    public function run(Variant $variant): void
    {
        match ($variant) {
            Variant::Floor => $this->db()->select(
                'select * from ' . TableSet::FLOOR . ' where id = ?',
                [$this->tables->probeRowId],
            ),
            Variant::Control => $this->db()->select(
                'select * from ' . TableSet::CONTROL . ' where id = ? and tenant_id = ?',
                [$this->tables->probeRowId, $this->tables->probeTenantId],
            ),
            Variant::Treatment => $this->rls()->isolateTo(
                ['tenant_id' => $this->tables->probeTenantId],
                fn() => $this->db()->select(
                    'select * from ' . TableSet::TREATMENT . ' where id = ?',
                    [$this->tables->probeRowId],
                ),
            ),
        };
    }

    public function explainTarget(): ?array
    {
        return $this->treatmentExplain(
            'select * from ' . TableSet::TREATMENT . ' where id = ?',
            [$this->tables->probeRowId],
        );
    }
}
