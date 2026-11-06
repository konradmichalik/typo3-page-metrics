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

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{MetricTile, TileStats};

use function array_filter;
use function array_sum;
use function array_values;
use function count;
use function max;
use function round;

/**
 * TileStatBuilder.
 *
 * PageMetricsTile.togglePopover() in sparkline.js computes the same four
 * figures client-side from a tile's data-sparkline attribute, for a surface
 * that opens on a click. This widget's stats have no click to wait for, so
 * the same math is built here instead, straight off MetricTile::$sparkline -
 * the two must keep agreeing, since a reader who has seen both would
 * otherwise wonder which one is wrong.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class TileStatBuilder
{
    public function __construct(
        private MetricValueFormatter $valueFormatter,
    ) {}

    public function build(MetricTile $tile): TileStats
    {
        // Re-keyed to a plain 0-based list: the sparkline's own keys are
        // Y-m-d strings in chronological order, and the last two entries of
        // that order are what "last day" and "previous day" mean.
        $days = array_values($tile->sparkline);
        $lastDay = $days[count($days) - 1] ?? null;
        $previousDay = $days[count($days) - 2] ?? null;

        // A day with no number at all is not a day of zero traffic (see
        // MetricTile::$sparkline) and must not pull the peak or the average
        // down towards it.
        $withData = array_filter($days, static fn (?int $value): bool => null !== $value);

        return new TileStats(
            null !== $lastDay ? $this->valueFormatter->format($lastDay, $tile->unit) : null,
            null !== $previousDay ? $this->valueFormatter->format($previousDay, $tile->unit) : null,
            [] !== $withData ? $this->valueFormatter->format(max($withData), $tile->unit) : null,
            [] !== $withData ? $this->valueFormatter->format((int) round(array_sum($withData) / count($withData)), $tile->unit) : null,
        );
    }
}
