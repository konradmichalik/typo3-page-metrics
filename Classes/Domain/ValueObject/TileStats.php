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

/**
 * TileStats.
 *
 * The same period figures the tile's own popover computes client-side in
 * sparkline.js (see PageMetricsTile.togglePopover() there) - last day,
 * previous day, peak and average across the tile's sparkline - built here
 * in PHP instead for a surface with no popover to open, see TileStatBuilder.
 *
 * A null field means the underlying day(s) carry no data at all, which is
 * not the same as a day with zero traffic (see MetricTile::$sparkline) -
 * left null rather than formatted as "0", the same reasoning
 * MetricTile::$previousFormattedValue already follows.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class TileStats
{
    public function __construct(
        public ?string $lastDayFormattedValue,
        public ?string $previousDayFormattedValue,
        public ?string $peakFormattedValue,
        public ?string $averageFormattedValue,
    ) {}
}
