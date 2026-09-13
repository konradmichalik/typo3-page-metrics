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
 * TopPage.
 *
 * One row of the "most viewed pages" or "trend" widget - a page UID
 * resolved to its current title, with its value and trend against the
 * previous period of the same length.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TopPage
{
    /**
     * Fluid's `!== null` in an f:if condition does not actually compare
     * against PHP null (TYPO3Fluid\Fluid\Core\Parser\BooleanParser has no
     * concept of a null literal - a bare `null` token falls through to
     * being treated as the *string* "null", so the comparison is never
     * false) - this precomputed flag is what templates check instead.
     */
    public bool $isNew;

    /**
     * Why the row has no percentage, where the reason is not the page: the
     * period ranked against lies outside the imported window, so its sum is
     * built out of days that were never fetched (see
     * ImportCoverage::covers()). Same precomputed shape as $isNew and for
     * the same reason, and the counterpart of MetricTile::$hasNoComparison -
     * the widget tables render no badge where it is true, because "new" is
     * a claim about the page and this is a claim about the import.
     */
    public bool $hasNoComparison;

    /**
     * Same reasoning as MetricTile::$sentiment: the row's colour has to say
     * "better / worse", which direction alone cannot for a metric where a
     * rise is the bad outcome.
     */
    public TrendSentiment $sentiment;

    /**
     * A second metric's value for the same page over the same period, which
     * "most viewed pages" uses for a configured goal's conversions beside
     * the traffic it ranks by. Null means the row has no second column at
     * all, which is not the same as a row whose goal value is zero: the
     * importers store no row for a page that converted nothing (see
     * MatomoGoalMetrics::readValue()), so an absent row is the stored shape
     * of "it did not happen" and is formatted as the zero it is, the same
     * number the page's own goal tile shows.
     *
     * Already formatted rather than an int, because the goal decides its own
     * unit: a revenue goal is stored in minor units and reads as money.
     *
     * It carries no comparison of its own. One period's conversions beside
     * one period's traffic is what the column claims, so there is nothing
     * for an uncovered previous period to be claimed about.
     *
     * Required rather than defaulted, like every other parameter here: this
     * object is not `@api` and has one call site, so "no second column" is
     * something that call site states rather than something it omits.
     */
    public function __construct(
        public int $pageUid,
        public string $title,
        public string $formattedValue,
        public ?int $deltaPercent,
        public TrendDirection $trend,
        public MetricPolarity $polarity,
        bool $previousPeriodCovered,
        public ?string $secondaryFormattedValue,
    ) {
        // And not covered: a row the import cannot speak for is not a new
        // page, it is a page nobody asked about. The templates branch on
        // $hasNoComparison first, so this only decides what a row that does
        // have a comparison says, but leaving the two collapsed here would
        // hand the next reader of this object the same trap MetricTile just
        // got out of.
        $this->isNew = null === $deltaPercent && $previousPeriodCovered;
        $this->hasNoComparison = !$previousPeriodCovered;
        $this->sentiment = TrendSentiment::of($trend, $polarity);
    }
}
