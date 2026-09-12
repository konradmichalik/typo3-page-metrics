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

namespace KonradMichalik\Typo3PageMetrics\Domain\ValueObject;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;

/**
 * ChartSeries.
 *
 * One additional metric overlaid on the chart widget's primary series (see
 * SiteMetricsOverview::$additionalChartSeries) - the primary metric keeps
 * its own dedicated fields on that class, since every other site-metrics
 * widget only ever reads those and has no notion of "additional" series.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ChartSeries
{
    /**
     * @param array<string, int|null> $series Y-m-d => value, covers the same period as the primary series;
     *                                        null for a day with no data
     */
    public function __construct(
        public string $label,
        public string $color,
        public MetricUnit $unit,
        public array $series,
    ) {}
}
