<?php

declare(strict_types=1);

/*
 * This file is part of the "page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Domain\Enum;

/**
 * MetricUnit.
 *
 * Determines how a tile's raw integer value is formatted for display.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
enum MetricUnit: string
{
    case Count = 'count';
    case Seconds = 'seconds';
    case Percent = 'percent';
}
