<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Report;

use function array_filter;
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

        $treatment = array_filter(
            $cells,
            static fn(array $c): bool => ($c['variant'] ?? null) === 'treatment',
        );
        $control = array_filter(
            $cells,
            static fn(array $c): bool => ($c['variant'] ?? null) === 'control',
        );

        $treatmentCell = reset($treatment);
        $controlCell = reset($control);

        $p50Delta = $treatmentCell && $controlCell
            ? (float) $treatmentCell['p50_us'] - (float) $controlCell['p50_us']
            : 0.0;
        $p99Delta = $treatmentCell && $controlCell
            ? (float) $treatmentCell['p99_us'] - (float) $controlCell['p99_us']
            : 0.0;

        /** @var list<array<string,mixed>> $amortization */
        $amortization = $document['amortization'] ?? [];
        $amortCell = reset($amortization);
        $fixed = $amortCell ? (float) $amortCell['derived_fixed_setconfig_us'] : 0.0;

        return sprintf(
            'adds ~%s us p50 / ~%s us p99 per query and ~one round-trip (~%s us) per transaction',
            number_format($p50Delta, 2),
            number_format($p99Delta, 2),
            number_format($fixed, 2),
        );
    }
}
