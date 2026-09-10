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
 * MetricDefinition.
 *
 * Display and cardinality metadata for one metric identifier. The platform
 * never interprets what a metric means - only a provider's MetricDefinition
 * does, via label/icon/unit.
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
        public ?int $maxDimensions = null,
    ) {}
}
