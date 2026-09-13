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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricPolarity, TrendDirection, TrendSentiment};

/**
 * SitePagesRow.
 *
 * One page of the site-wide pages table: every metric's value for the
 * period, and the movement of the one metric the table is sorted by.
 *
 * The three derived flags are computed here exactly as TopPage computes
 * them, and mean the same thing: $isNew is a claim about the page, and
 * $hasNoComparison is a claim about the import (see ImportCoverage::covers()).
 * Restated rather than shared because TopPage and MetricTile already carry
 * the same three and neither is reachable from here without turning a row
 * of one surface into a row of another.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SitePagesRow
{
    public bool $isNew;
    public bool $hasNoComparison;
    public TrendSentiment $sentiment;

    /**
     * @param list<SitePagesCell> $cells one per column of the table, in the table's own column order
     */
    public function __construct(
        public int $pageUid,
        public string $title,
        public array $cells,
        public ?int $deltaPercent,
        public TrendDirection $trend,
        public MetricPolarity $polarity,
        bool $previousPeriodCovered,
    ) {
        $this->isNew = null === $deltaPercent && $previousPeriodCovered;
        $this->hasNoComparison = !$previousPeriodCovered;
        $this->sentiment = TrendSentiment::of($trend, $polarity);
    }
}
