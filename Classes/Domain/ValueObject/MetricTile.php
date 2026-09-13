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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricPolarity, MetricUnit, TrendDirection, TrendSentiment};

/**
 * MetricTile.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MetricTile
{
    /**
     * Whether this tile's movement is an improvement, derived from the
     * direction and the metric's own polarity. Templates colour the trend
     * badge by this, never by $trend: a rising bounce rate points up and is
     * bad at the same time.
     */
    public TrendSentiment $sentiment;

    /**
     * Same reason as TopPage::$isNew: Fluid's boolean parser has no concept
     * of a null literal, so a template cannot ask whether $deltaPercent is
     * null. The badge needs to know, because "improved by new" is not a
     * sentence.
     */
    public bool $isNew;

    /**
     * The third state, and the reason $isNew alone was not enough: a null
     * $deltaPercent used to mean both "new since the previous period" and
     * "the previous period was never imported", which are different facts
     * about the page and only one of them is about the page at all. A period
     * outside the imported window sums to a zero nobody measured, and the
     * tile said "new" about a page that has existed for years. Precomputed
     * like $isNew, for a template that cannot ask the question itself, and
     * the badge is left out entirely where it is true.
     */
    public bool $hasNoComparison;

    /**
     * @param array<string, int|null>                            $sparkline              Y-m-d => value, covers the same period as the tile
     *                                                                                   itself; null for a day with no data at all, which is
     *                                                                                   not the same as a day with no traffic
     * @param int|null                                           $deltaPercent           null when the previous period's total was zero and the
     *                                                                                   current period's is not - there is no meaningful percentage
     *                                                                                   base, rendered as "new" rather than a false infinite/undefined
     *                                                                                   percentage
     * @param string                                             $previousFormattedValue the previous period's own value, formatted the same way as
     *                                                                                   $formattedValue - empty when $deltaPercent is null, since
     *                                                                                   there is nothing meaningful to show
     * @param list<array{label: string, formattedValue: string}> $breakdown              what this tile's number is made of, shown in
     *                                                                                   its popover. Empty for a tile that stands for one metric,
     *                                                                                   which is all of them except the machine-traffic summary:
     *                                                                                   that one folds a provider's whole MetricGroup::Machine set
     *                                                                                   into a single headline, and the parts are the only way back
     *                                                                                   to which crawler did what
     * @param TileBenchmark|null                                 $benchmark              what the rest of this site did on the same metric over
     *                                                                                   the same period, null where the site has too few pages
     *                                                                                   with data for the comparison to say anything
     * @param int                                                $previousValue          the previous period's own figure as a number rather than
     *                                                                                   as the formatted string beside it, because the CSV export
     *                                                                                   carries raw values in their own unit and a display string
     *                                                                                   taken back apart is a reading aid used as a datum. Zero
     *                                                                                   where there is nothing to compare against, which
     *                                                                                   $isNew and $hasNoComparison already tell apart
     * @param bool                                               $previousPeriodCovered  whether the period compared against lies inside the
     *                                                                                   imported window, which is what makes the comparison
     *                                                                                   sayable at all (see ImportCoverage::covers()). Defaults
     *                                                                                   to true because a tile built by a listener rather than by
     *                                                                                   the import pipeline brought its own numbers and knows no
     *                                                                                   import window, and because this is an `@api` constructor
     *                                                                                   a required parameter here would break every existing
     *                                                                                   ModifyMetricTilesEvent listener
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
        public string $previousFormattedValue,
        public MetricPolarity $polarity = MetricPolarity::HigherIsBetter,
        public array $breakdown = [],
        public ?TileBenchmark $benchmark = null,
        bool $previousPeriodCovered = true,
        public int $previousValue = 0,
    ) {
        $this->sentiment = TrendSentiment::of($trend, $polarity);
        $this->hasNoComparison = !$previousPeriodCovered;
        $this->isNew = null === $deltaPercent && $previousPeriodCovered;
    }
}
