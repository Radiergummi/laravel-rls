<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Report;

use function array_filter;
use function end;
use function number_format;
use function reset;
use function sprintf;

final class MarkdownReporter
{
    /**
     * @param array<string,mixed> $document
     */
    public function render(array $document): string
    {
        $lines = [];
        $lines[] = '# RLS performance baseline';
        $lines[] = '';
        $lines[] = '| scenario | scale | variant | p50 (us) | p99 (us) |';
        $lines[] = '|---|---|---|---|---|';

        /** @var list<array<string,mixed>> $cells */
        $cells = $document['cells'] ?? [];
        foreach ($cells as $cell) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                $cell['scenario'],
                $cell['scale'],
                $cell['variant'],
                number_format((float) $cell['p50_us'], 2),
                number_format((float) $cell['p99_us'], 2),
            );
        }

        $lines[] = '';
        $lines[] = 'Headline: ' . $this->headline($document);

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string,mixed> $document
     */
    public function headline(array $document): string
    {
        /** @var list<array<string,mixed>> $cells */
        $cells = $document['cells'] ?? [];

        $pointSelectCells = array_filter(
            $cells,
            static fn(array $c): bool => ($c['scenario'] ?? null) === 'point_select',
        );

        /** @var list<string> $scales */
        $scales = $document['params']['scales'] ?? [];
        $scale = $scales !== [] ? end($scales) : null;

        $hasChosenScale = array_filter(
            $pointSelectCells,
            static fn(array $c): bool => ($c['scale'] ?? null) === $scale,
        );

        if ($scale === null || $hasChosenScale === []) {
            $fallbackCell = reset($pointSelectCells);
            $scale = $fallbackCell ? $fallbackCell['scale'] : null;
        }

        $treatmentCell = $this->findCell($pointSelectCells, $scale, 'treatment');
        $controlCell = $this->findCell($pointSelectCells, $scale, 'control');

        $p50Delta = $treatmentCell && $controlCell
            ? number_format((float) $treatmentCell['p50_us'] - (float) $controlCell['p50_us'], 2)
            : 'n/a';
        $p99Delta = $treatmentCell && $controlCell
            ? number_format((float) $treatmentCell['p99_us'] - (float) $controlCell['p99_us'], 2)
            : 'n/a';

        /** @var list<array<string,mixed>> $amortization */
        $amortization = $document['amortization'] ?? [];
        $matchingAmortization = $scale !== null
            ? array_filter(
                $amortization,
                static fn(array $a): bool => ($a['scale'] ?? null) === $scale,
            )
            : [];
        $amortCell = reset($matchingAmortization);
        $fixed = $amortCell ? number_format((float) $amortCell['derived_fixed_setconfig_us'], 2) : 'n/a';

        return sprintf(
            'adds ~%s us p50 / ~%s us p99 per query and ~one round-trip (~%s us) per transaction',
            $p50Delta,
            $p99Delta,
            $fixed,
        );
    }

    /**
     * @param list<array<string,mixed>> $cells
     *
     * @return array<string,mixed>|null
     */
    private function findCell(array $cells, ?string $scale, string $variant): ?array
    {
        $matches = array_filter(
            $cells,
            static fn(array $c): bool => ($c['scale'] ?? null) === $scale && ($c['variant'] ?? null) === $variant,
        );

        $cell = reset($matches);

        return $cell === false ? null : $cell;
    }
}
