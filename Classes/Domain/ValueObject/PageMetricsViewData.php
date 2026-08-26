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

/**
 * PageMetricsViewData.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PageMetricsViewData
{
    /**
     * @param list<MetricTile> $tiles
     * @param list<int>        $periodOptions
     */
    public function __construct(
        public bool $hasPage,
        public int $pageId,
        public array $tiles,
        public int $unresolvedCount,
        public int $periodDays,
        public array $periodOptions,
    ) {}
}
