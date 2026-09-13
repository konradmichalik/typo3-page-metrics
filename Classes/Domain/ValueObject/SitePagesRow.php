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
     * Carried as its own flag because the template has to tell "changed
     * today" from "no record of when", and a Fluid condition on
     * $daysSinceChange reads zero days and no age as the same thing.
     */
    public bool $hasAge;

    /**
     * @param list<SitePagesCell> $cells           one per column of the table, in the table's own column order
     * @param int|null            $daysSinceChange whole days since the page or its content was last edited, null where the
     *                                             record carries no timestamp at all: that is an unknown age rather than a
     *                                             change in 1970, and it must not head a list ordered by age
     * @param bool                $isDecaying      the page lost the metric this table judges on, by enough to clear that
     *                                             metric's own MetricSignificance, and nobody has touched it since before
     *                                             the two compared periods began. A page that never had the metric at all
     *                                             is not decaying: MetricSignificance clears nothing against a previous
     *                                             value of zero, so a page that was never found stays out of the verdict
     */
    public function __construct(
        public int $pageUid,
        public string $title,
        public array $cells,
        public ?int $deltaPercent,
        public TrendDirection $trend,
        public MetricPolarity $polarity,
        bool $previousPeriodCovered,
        public ?int $daysSinceChange,
        public bool $isDecaying,
    ) {
        $this->isNew = null === $deltaPercent && $previousPeriodCovered;
        $this->hasNoComparison = !$previousPeriodCovered;
        $this->sentiment = TrendSentiment::of($trend, $polarity);
        $this->hasAge = null !== $daysSinceChange;
    }
}
