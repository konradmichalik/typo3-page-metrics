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

use function intdiv;
use function sprintf;

/**
 * MetricValueFormatter.
 *
 * A metric's integer value as the string a tile shows. Every value in this
 * extension is stored as an integer in its own unit, so a percentage, a
 * duration and a plain count are indistinguishable until the unit says which
 * is which.
 *
 * Extracted because the page module and the trend list had a copy each, and a
 * unit formatted one way in a tile and another way in the table beside it is
 * the kind of divergence nobody notices until a duration reads as 143.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MetricValueFormatter
{
    public function format(int $value, MetricUnit $unit): string
    {
        return match ($unit) {
            MetricUnit::Percent => sprintf('%d%%', $value),
            // Minutes and seconds rather than a bare count: "0:03" is a time
            // on page, "3" is a number nobody can place.
            MetricUnit::Seconds => sprintf('%d:%02d', intdiv($value, 60), $value % 60),
            MetricUnit::Count => (string) $value,
        };
    }
}
