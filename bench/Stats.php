<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

use InvalidArgumentException;

use function array_map;
use function array_sum;
use function array_values;
use function ceil;
use function count;
use function max;
use function min;
use function sort;
use function sqrt;

/**
 * Pure statistics over a sample array. Input is nanosecond durations; output is microseconds.
 */
final class Stats
{
    /**
     * @param list<int|float> $samplesNs nanosecond durations
     *
     * @return array{n:int,mean_us:float,stddev_us:float,min_us:float,max_us:float,p50_us:float,p90_us:float,p95_us:float,p98_us:float,p99_us:float}
     */
    public static function summarize(array $samplesNs): array
    {
        if ($samplesNs === []) {
            throw new InvalidArgumentException('Cannot summarize an empty sample set.');
        }

        $us = array_map(static fn($ns): float => $ns / 1000.0, array_values($samplesNs));
        sort($us);

        $n = count($us);
        $mean = array_sum($us) / $n;

        $variance = 0.0;
        foreach ($us as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $stddev = $n > 1 ? sqrt($variance / ($n - 1)) : 0.0;

        return [
            'n' => $n,
            'mean_us' => $mean,
            'stddev_us' => $stddev,
            'min_us' => $us[0],
            'max_us' => $us[$n - 1],
            'p50_us' => self::percentile($us, 50),
            'p90_us' => self::percentile($us, 90),
            'p95_us' => self::percentile($us, 95),
            'p98_us' => self::percentile($us, 98),
            'p99_us' => self::percentile($us, 99),
        ];
    }

    /**
     * Nearest-rank percentile on an ascending-sorted list.
     *
     * @param list<float> $sorted
     */
    private static function percentile(array $sorted, int $p): float
    {
        $n = count($sorted);
        $rank = (int) ceil($p / 100 * $n);
        $index = max(1, min($rank, $n)) - 1;

        return $sorted[$index];
    }
}
