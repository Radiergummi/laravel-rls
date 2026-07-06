<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Report;

use JsonException;

use function file_put_contents;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_EOL;

final class JsonReporter
{
    /**
     * @template TEnv of array<string, mixed>
     * @template TParams of array<string, mixed>
     * @template TCell of list<array<string, mixed>>
     * @template TAmortization of list<array<string, mixed>>
     * @template TExplain of list<array<string, mixed>>
     * @template TEndpoints of list<array<string, mixed>>
     * @template TLatencySweep of list<array<string, mixed>>
     *
     * @param TEnv          $env
     * @param TParams       $params
     * @param TCell         $cells
     * @param TAmortization $amortization
     * @param TExplain      $explain
     * @param TEndpoints    $endpoints
     * @param TLatencySweep $latencySweep
     *
     * @return array{
     *     env: TEnv,
     *     params: TParams,
     *     cells: TCell,
     *     amortization: TAmortization,
     *     explain: TExplain,
     *     endpoints: TEndpoints,
     *     latency_sweep: TLatencySweep
     * }
     */
    public function render(
        array $env,
        array $params,
        array $cells,
        array $amortization,
        array $explain,
        array $endpoints,
        array $latencySweep,
    ): array {
        return [
            'env' => $env,
            'params' => $params,
            'cells' => $cells,
            'amortization' => $amortization,
            'explain' => $explain,
            'endpoints' => $endpoints,
            'latency_sweep' => $latencySweep,
        ];
    }

    /**
     * @param array<string,mixed> $document
     *
     * @throws JsonException
     */
    public function write(string $path, array $document): void
    {
        file_put_contents(
            $path,
            json_encode(
                $document,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        );
    }
}
