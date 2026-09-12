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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricGroup, MetricPolarity, MetricUnit};

/**
 * MetricDefinition.
 *
 * Display and cardinality metadata for one metric identifier. The platform
 * never interprets what a metric means - only a provider's MetricDefinition
 * does, via label/icon/unit.
 *
 * $group is appended rather than slotted in, unlike $polarity, which took
 * the place $maxDimensions had freed. Appending is safe here precisely
 * because there is no existing slot to land in: a positional third-party
 * call passing the arguments this constructor already had keeps meaning what
 * it meant, and one passing a ninth did not compile before.
 *
 * $significance is null for a provider that has not thought about it, which
 * is every provider until it has volumes to calibrate against. The trend
 * ranking resolves that to MetricSignificance::default() rather than storing
 * a default here, so the day the default changes it changes for every metric
 * that never named one instead of for none of them.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MetricDefinition
{
    public function __construct(
        public string $identifier,
        public string $label,
        public string $description,
        public string $icon,
        public MetricUnit $unit,
        public int $sortOrder,
        public string $color,
        public MetricPolarity $polarity = MetricPolarity::HigherIsBetter,
        public MetricGroup $group = MetricGroup::Audience,
        public ?MetricSignificance $significance = null,
    ) {}
}
