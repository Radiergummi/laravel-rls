<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

final readonly class TableSet
{
    public const FLOOR = 'bench_floor';
    public const CONTROL = 'bench_control';
    public const TREATMENT = 'bench_treatment';

    public function __construct(
        public string $scale,
        public string $probeTenantId,
        public string $probeRowId,
        public int $probeRangeLo,
        public int $probeRangeHi,
    ) {}
}
