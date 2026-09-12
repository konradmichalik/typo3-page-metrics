<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Service;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;

/**
 * RateSeriesCalculator.
 *
 * A bounce rate or an average duration is a ratio, not a count: summing
 * daily ratios would weight a quiet day the same as a busy one, so a rate is
 * always sum(numerator) / sum(denominator) over whatever range it covers.
 *
 * The same arithmetic was written out three times, byte for byte, before
 * this existed: page tiles, dashboard charts and the per-page ranking.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RateSeriesCalculator
{
    public function ratio(int $numerator, int $denominator, MetricUnit $unit): int
    {
        if (0 === $denominator) {
            return 0;
        }

        $ratio = $numerator / $denominator;

        return MetricUnit::Percent === $unit ? (int) round($ratio * 100) : (int) round($ratio);
    }

    /**
     * @param array<string, int> $numeratorSeries
     * @param array<string, int> $denominatorSeries
     *
     * @return array<string, int>
     */
    public function series(array $numeratorSeries, array $denominatorSeries, MetricUnit $unit): array
    {
        $series = [];

        foreach ($numeratorSeries as $date => $numeratorValue) {
            $series[$date] = $this->ratio($numeratorValue, $denominatorSeries[$date] ?? 0, $unit);
        }

        return $series;
    }
}
