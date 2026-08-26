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

namespace KonradMichalik\Typo3PageMetrics\Domain\ValueObject;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;

/**
 * RateMetricDefinition.
 *
 * Unlike MetricDefinition, this describes a metric that is not itself
 * summable across days (a bounce rate or an average duration is a ratio,
 * not a count) - its tile value is derived as sum(numerator) / sum(denominator)
 * over a period rather than a direct sum of stored daily values. numerator
 * and denominator are themselves plain, summable metric identifiers stored
 * the same way as any MetricDefinition-backed metric.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RateMetricDefinition
{
    public function __construct(
        public string $identifier,
        public string $label,
        public string $description,
        public string $icon,
        public MetricUnit $unit,
        public int $sortOrder,
        public string $color,
        public string $numerator,
        public string $denominator,
    ) {}
}
