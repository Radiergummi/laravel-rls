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

        $lines[] = '';
        $lines[] = '## Endpoints';
        $lines[] = '';
        $lines[] = '| config | k | status | control (us) | treatment (us) | overhead (us) | per-query (us) |';
        $lines[] = '|---|---|---|---|---|---|---|';

        /** @var list<array<string,mixed>> $endpoints */
        $endpoints = $document['endpoints'] ?? [];

        foreach ($endpoints as $endpoint) {
            if (($endpoint['status'] ?? '') === 'unsafe') {
                $lines[] = sprintf('| %s | %s | unsafe | — | — | — | — |', $endpoint['label'], $endpoint['k']);

                continue;
            }

            $lines[] = sprintf(
                '| %s | %s | ok | %s | %s | %s | %s |',
                $endpoint['label'],
                $endpoint['k'],
                number_format((float) $endpoint['control_us'], 2),
                number_format((float) $endpoint['treatment_us'], 2),
                number_format((float) $endpoint['overhead_endpoint_us'], 2),
                number_format((float) $endpoint['overhead_per_query_us'], 2),
            );
        }

        /** @var list<array<string,mixed>> $sweep */
        $sweep = $document['latency_sweep'] ?? [];

        if ($sweep !== []) {
            $lines[] = '';
            $lines[] = '## Latency sweep';
            $lines[] = '';
            $lines[] = '| config | injected (ms) | control (us) | treatment (us) | overhead (us) |';
            $lines[] = '|---|---|---|---|---|';

            foreach ($sweep as $point) {
                $lines[] = sprintf(
                    '| %s | %s | %s | %s | %s |',
                    $point['label'],
                    $point['injected_ms'],
                    number_format((float) $point['control_us'], 2),
                    number_format((float) $point['treatment_us'], 2),
                    number_format((float) $point['overhead_endpoint_us'], 2),
                );
            }
        }

        $lines[] = '';
        $lines[] = 'Endpoint headline: ' . $this->endpointHeadline($document);

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

        $p50Delta = $this->delta($treatmentCell, $controlCell, 'p50_us');
        $p99Delta = $this->delta($treatmentCell, $controlCell, 'p99_us');

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
     * The request-level story: how wrap's endpoint overhead grows with K while per-query stays flat.
     *
     * @param array<string,mixed> $document
     */
    public function endpointHeadline(array $document): string
    {
        /** @var list<array<string,mixed>> $endpoints */
        $endpoints = $document['endpoints'] ?? [];

        $wrapAt = static function (int $k) use ($endpoints): ?array {
            $matches = array_filter(
                $endpoints,
                static fn(array $e): bool => ($e['label'] ?? null) === 'direct·transaction·wrap'
                    && ($e['status'] ?? null) === 'ok'
                    && ($e['k'] ?? null) === $k,
            );
            $cell = reset($matches);

            return $cell === false ? null : $cell;
        };

        $low = $wrapAt(1);
        $high = $wrapAt(30);

        if ($low === null || $high === null) {
            return 'n/a';
        }

        return sprintf(
            'wrap endpoint overhead ~%s us (k=1) -> ~%s us (k=30), ~%s us/query flat; request/session stay ~flat in k',
            number_format((float) $low['overhead_endpoint_us'], 2),
            number_format((float) $high['overhead_endpoint_us'], 2),
            number_format((float) $high['overhead_per_query_us'], 2),
        );
    }

    /**
     * The treatment−control delta for one percentile field, or 'n/a' when either cell is missing.
     *
     * @param null|array<string,mixed> $treatment
     * @param null|array<string,mixed> $control
     */
    private function delta(?array $treatment, ?array $control, string $field): string
    {
        return $treatment && $control
            ? number_format((float) $treatment[$field] - (float) $control[$field], 2)
            : 'n/a';
    }

    /**
     * @param list<array<string,mixed>> $cells
     *
     * @return null|array<string,mixed>
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
