<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

use Closure;

use function hrtime;

final class Runner
{
    /**
     * Execute the operation `warmup` times (discarded) then `iterations` times, returning one
     * nanosecond duration per measured iteration.
     *
     * @param Closure(Variant): void $operation
     *
     * @return list<int>
     */
    public function measure(Closure $operation, Variant $variant, int $warmup, int $iterations): array
    {
        for ($i = 0; $i < $warmup; $i++) {
            $operation($variant);
        }

        $samples = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $operation($variant);
            $samples[] = hrtime(true) - $start;
        }

        return $samples;
    }
}
