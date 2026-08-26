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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricUnit, TrendDirection};

/**
 * MetricTile.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MetricTile
{
    /**
     * @param array<string, int> $sparkline     Y-m-d => value, fixed 30-day window regardless of the tile's own period
     * @param int|null           $deltaPercent  null when the previous period's total was zero and the current
     *                                          period's is not - there is no meaningful percentage base, rendered
     *                                          as "new" rather than a false infinite/undefined percentage
     */
    public function __construct(
        public string $identifier,
        public string $label,
        public string $description,
        public string $icon,
        public string $color,
        public MetricUnit $unit,
        public int $value,
        public string $formattedValue,
        public array $sparkline,
        public ?int $deltaPercent,
        public TrendDirection $trend,
        public int $redirectSharePercent,
        public ?string $externalLink,
    ) {}
}
