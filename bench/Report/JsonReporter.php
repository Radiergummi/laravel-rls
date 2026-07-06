<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench\Report;

use function file_put_contents;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

final class JsonReporter
{
    /**
     * @param array<string,mixed>       $env
     * @param array<string,mixed>       $params
     * @param list<array<string,mixed>> $cells
     * @param list<array<string,mixed>> $amortization
     * @param list<array<string,mixed>> $explain
     *
     * @return array<string,mixed>
     */
    public function render(array $env, array $params, array $cells, array $amortization, array $explain): array
    {
        return [
            'env' => $env,
            'params' => $params,
            'cells' => $cells,
            'amortization' => $amortization,
            'explain' => $explain,
        ];
    }

    /**
     * @param array<string,mixed> $document
     */
    public function write(string $path, array $document): void
    {
        file_put_contents(
            $path,
            json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }
}
