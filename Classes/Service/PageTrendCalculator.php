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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricPolarity, TrendDirection, TrendSentiment};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, MetricSignificance, MetricTrend, PageTrend, RateMetricDefinition, TopPage};
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, SitePageUidRepository};

use function array_slice;
use function sprintf;

/**
 * PageTrendCalculator.
 *
 * Per-page metric trend/ranking math split out of SiteMetricsOverviewBuilder
 * - "most viewed pages" (rankedByValue) and "biggest rise/drop" (rankedByTrend)
 * share the exact same per-page-per-period computation, only the final sort
 * axis differs. A RateMetricDefinition (bounce rate, avg. time on page) is
 * not itself summable across days, so it is computed as
 * sum(numerator)/sum(denominator) per page and period rather than a direct
 * daily sum, mirroring SiteMetricsOverviewBuilder::buildChartSeries()'s
 * per-day version of the same idea.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PageTrendCalculator
{
    public function __construct(
        private DailyMetricsRepository $dailyMetricsRepository,
        private SitePageUidRepository $pageUidRepository,
        private RateSeriesCalculator $rateSeriesCalculator,
        private MetricValueFormatter $valueFormatter,
    ) {}

    /**
     * $previousPeriodCovered is the caller's answer to whether the period
     * ranked against was imported at all (see ImportCoverage::covers()).
     * The ranking itself stands either way - the current period's numbers
     * are real - and only the comparison beside each row is withheld.
     *
     * $secondaryMetric is a second metric carried alongside each ranked row
     * without taking part in the ranking (see secondaryValues()).
     *
     * @param list<int> $pageUids
     *
     * @return list<TopPage>
     */
    public function rankedByValue(array $pageUids, int $languageId, MetricDefinition|RateMetricDefinition $metric, DateRange $currentRange, DateRange $previousRange, int $limit, bool $previousPeriodCovered = true, ?MetricDefinition $secondaryMetric = null): array
    {
        $trends = $this->computeTrends($pageUids, $languageId, $metric, $currentRange, $previousRange, $previousPeriodCovered);
        usort($trends, static fn (PageTrend $a, PageTrend $b): int => $b->value <=> $a->value);
        $ranked = array_slice($trends, 0, $limit);

        return $this->toTopPages($ranked, $metric, $previousPeriodCovered, $secondaryMetric, $this->secondaryValues($ranked, $languageId, $currentRange, $secondaryMetric));
    }

    /**
     * @param list<int> $pageUids
     *
     * @return list<TopPage>
     */
    public function rankedByTrend(array $pageUids, int $languageId, MetricDefinition|RateMetricDefinition $metric, DateRange $currentRange, DateRange $previousRange, TrendSentiment $sentiment, int $limit, bool $previousPeriodCovered = true): array
    {
        if (!$previousPeriodCovered) {
            // Nothing to rank rather than everything: this list is movement,
            // and a period the import did not cover produces a movement for
            // every page, sized by how much of it happened to be fetched. A
            // page with no previous row at all is already dropped by the
            // significance threshold, so it is the partly covered period that
            // used to fill the widget with risers nobody can account for.
            return [];
        }

        $polarity = $metric->polarity;
        $significance = $metric->significance ?? MetricSignificance::default();
        $trends = $this->computeTrends($pageUids, $languageId, $metric, $currentRange, $previousRange, $previousPeriodCovered);

        // Both thresholds, not just the direction. Filtering on direction
        // alone puts a page that went from two views to four at the top of
        // "what rose", above one that lost a thousand, which is the opposite
        // of what a widget called "what moved" is for.
        $trends = array_values(array_filter(
            $trends,
            static fn (PageTrend $trend): bool => $sentiment === self::rankingSentimentOf($trend->direction, $polarity)
                && $significance->clears($trend->value, $trend->previousValue),
        ));

        // Ranked by how much the movement is worth attending to, which is
        // its size weighted by the volume behind it. Sorting by percentage
        // is what the threshold above already had to correct for, and would
        // reintroduce the same inversion inside the list that survived it.
        //
        // The sentiment does not enter the order. Everything left is already
        // on the asked-for side of flat, so the largest movement leads
        // whether "largest" means the steepest fall or the steepest rise.
        usort(
            $trends,
            static fn (PageTrend $a, PageTrend $b): int => $b->weight() <=> $a->weight(),
        );

        return $this->toTopPages(array_slice($trends, 0, $limit), $metric, $previousPeriodCovered);
    }

    /**
     * The rolled-up counterpart to rankedByValue(): each ranked row is not
     * one page but a whole subtree (see SitePageUidRepository::descendantsOf()),
     * with the anchor page's own value plus every one of its subpages' -
     * used by "most viewed pages" when both a page level and "include
     * subpages" are configured, so a section's own traffic and its
     * subpages' traffic show as one number under that section instead of
     * separate rows. MetricDefinition only, unlike the other ranking
     * methods: "most viewed pages" has no metric picker of its own, it
     * always ranks by the site's primary metric (see
     * SiteMetricsOverviewBuilder::primaryDefinition(), whose return type is
     * MetricDefinition, never RateMetricDefinition) - a rate metric here
     * would be unreachable code, not a real capability.
     *
     * @param array<int, list<int>> $pageUidsByAnchor anchor page uid => every page uid whose data rolls up into it (including the anchor itself)
     *
     * @return list<TopPage>
     */
    public function rankedByValueGrouped(array $pageUidsByAnchor, int $languageId, MetricDefinition $metric, DateRange $currentRange, DateRange $previousRange, int $limit, bool $previousPeriodCovered = true, ?MetricDefinition $secondaryMetric = null): array
    {
        $trends = $this->computeGroupedTrends($pageUidsByAnchor, $languageId, $metric->identifier, $currentRange, $previousRange, $previousPeriodCovered);
        usort($trends, static fn (PageTrend $a, PageTrend $b): int => $b->value <=> $a->value);
        $ranked = array_slice($trends, 0, $limit);

        return $this->toTopPages($ranked, $metric, $previousPeriodCovered, $secondaryMetric, $this->secondaryValuesGrouped($ranked, $pageUidsByAnchor, $languageId, $currentRange, $secondaryMetric));
    }

    /**
     * A metric with no polarity (an average time on page is neither good nor
     * bad when it rises) would otherwise produce nothing but
     * TrendSentiment::Neutral, making the trend widget permanently empty for
     * it. For those the widget's setting keeps its literal direction
     * meaning: "improved" reads as "went up".
     */
    private static function rankingSentimentOf(TrendDirection $direction, MetricPolarity $polarity): TrendSentiment
    {
        if (MetricPolarity::Neutral !== $polarity) {
            return TrendSentiment::of($direction, $polarity);
        }

        return match ($direction) {
            TrendDirection::Up => TrendSentiment::Positive,
            TrendDirection::Down => TrendSentiment::Negative,
            TrendDirection::Flat => TrendSentiment::Neutral,
        };
    }

    /**
     * @param list<int> $pageUids
     *
     * @return list<PageTrend>
     */
    private function computeTrends(array $pageUids, int $languageId, MetricDefinition|RateMetricDefinition $metric, DateRange $currentRange, DateRange $previousRange, bool $previousPeriodCovered): array
    {
        return $metric instanceof MetricDefinition
            ? $this->computePageTrends($pageUids, $languageId, $metric->identifier, $currentRange, $previousRange, $previousPeriodCovered)
            : $this->computePageTrendsForRate($pageUids, $languageId, $metric, $currentRange, $previousRange, $previousPeriodCovered);
    }

    /**
     * Every page with data in $currentRange and/or $previousRange, with its
     * trend against the previous period. A page with zero current traffic
     * but real previous traffic still appears here (a real, meaningful
     * drop), which is why this does not simply reuse
     * DailyMetricsRepository::topPages().
     *
     * @param list<int> $pageUids
     *
     * @return list<PageTrend>
     */
    private function computePageTrends(array $pageUids, int $languageId, string $metric, DateRange $currentRange, DateRange $previousRange, bool $previousPeriodCovered): array
    {
        $currentSums = $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric, $currentRange);
        // Not asked for outside the imported window: the sum would be made of
        // days that were never fetched, which is not a smaller number, it is
        // no number at all.
        $previousSums = $previousPeriodCovered ? $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric, $previousRange) : [];

        $trends = [];
        foreach (array_unique([...array_keys($currentSums), ...array_keys($previousSums)]) as $pageUid) {
            $value = $currentSums[$pageUid] ?? 0;
            $previousValue = $previousSums[$pageUid] ?? 0;

            if (0 === $value && 0 === $previousValue) {
                continue;
            }

            $metricTrend = $previousPeriodCovered ? MetricTrend::between($value, $previousValue) : MetricTrend::none();
            // The count itself is the volume: a page's traffic is both what
            // moved and the scale it moved at.
            $trends[] = new PageTrend($pageUid, $value, $previousValue, max($value, $previousValue), $metricTrend->deltaPercent, $metricTrend->direction);
        }

        return $trends;
    }

    /**
     * @param array<int, list<int>> $pageUidsByAnchor
     *
     * @return list<PageTrend>
     */
    private function computeGroupedTrends(array $pageUidsByAnchor, int $languageId, string $metric, DateRange $currentRange, DateRange $previousRange, bool $previousPeriodCovered): array
    {
        $allPageUids = $this->flattenAnchorPageUids($pageUidsByAnchor);
        $currentSums = $this->dailyMetricsRepository->sumsByPage($allPageUids, $languageId, $metric, $currentRange);
        $previousSums = $previousPeriodCovered ? $this->dailyMetricsRepository->sumsByPage($allPageUids, $languageId, $metric, $previousRange) : [];

        $trends = [];
        foreach ($pageUidsByAnchor as $anchorUid => $pageUids) {
            $value = $this->sumForPages($pageUids, $currentSums);
            $previousValue = $this->sumForPages($pageUids, $previousSums);

            if (0 === $value && 0 === $previousValue) {
                continue;
            }

            $metricTrend = $previousPeriodCovered ? MetricTrend::between($value, $previousValue) : MetricTrend::none();
            $trends[] = new PageTrend($anchorUid, $value, $previousValue, max($value, $previousValue), $metricTrend->deltaPercent, $metricTrend->direction);
        }

        return $trends;
    }

    /**
     * @param array<int, list<int>> $pageUidsByAnchor
     *
     * @return list<int>
     */
    private function flattenAnchorPageUids(array $pageUidsByAnchor): array
    {
        return array_values(array_unique(array_merge([], ...array_values($pageUidsByAnchor))));
    }

    /**
     * @param list<int>       $pageUids
     * @param array<int, int> $sumsByPage
     */
    private function sumForPages(array $pageUids, array $sumsByPage): int
    {
        $total = 0;
        foreach ($pageUids as $pageUid) {
            $total += $sumsByPage[$pageUid] ?? 0;
        }

        return $total;
    }

    /**
     * "No data" is judged on the denominator (e.g. visits), not the derived
     * ratio: a page with zero denominator in both periods never had this
     * metric recorded at all, whereas a ratio of zero (e.g. 0% bounce rate)
     * is a real, meaningful value.
     *
     * @param list<int> $pageUids
     *
     * @return list<PageTrend>
     */
    private function computePageTrendsForRate(array $pageUids, int $languageId, RateMetricDefinition $metric, DateRange $currentRange, DateRange $previousRange, bool $previousPeriodCovered): array
    {
        $currentNumerator = $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric->numerator, $currentRange);
        $currentDenominator = $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric->denominator, $currentRange);
        $previousNumerator = $previousPeriodCovered ? $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric->numerator, $previousRange) : [];
        $previousDenominator = $previousPeriodCovered ? $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric->denominator, $previousRange) : [];

        $trends = [];
        foreach (array_unique([...array_keys($currentDenominator), ...array_keys($previousDenominator)]) as $pageUid) {
            $denominator = $currentDenominator[$pageUid] ?? 0;
            $previousDenominatorValue = $previousDenominator[$pageUid] ?? 0;

            if (0 === $denominator && 0 === $previousDenominatorValue) {
                continue;
            }

            $value = $this->rateSeriesCalculator->ratio($currentNumerator[$pageUid] ?? 0, $denominator, $metric->unit);
            $previousValue = $this->rateSeriesCalculator->ratio($previousNumerator[$pageUid] ?? 0, $previousDenominatorValue, $metric->unit);

            $metricTrend = $previousPeriodCovered ? MetricTrend::between($value, $previousValue) : MetricTrend::none();
            // The denominator, not the rate: a bounce rate is a share, and a
            // share of four visits is not a movement of the same size as the
            // same share of four thousand.
            $trends[] = new PageTrend($pageUid, $value, $previousValue, max($denominator, $previousDenominatorValue), $metricTrend->deltaPercent, $metricTrend->direction);
        }

        return $trends;
    }

    /**
     * A page missing from $secondaryValues is formatted as a zero rather
     * than left out: the importers write no row for a page that converted
     * nothing (see MatomoGoalMetrics::readValue()), so its absence is how
     * "it did not happen" is stored, and the page's own goal tile says the
     * same zero.
     *
     * @param list<PageTrend> $trends
     * @param array<int, int> $secondaryValues page uid => $secondaryMetric's summed value
     *
     * @return list<TopPage>
     */
    private function toTopPages(array $trends, MetricDefinition|RateMetricDefinition $metric, bool $previousPeriodCovered, ?MetricDefinition $secondaryMetric = null, array $secondaryValues = []): array
    {
        $titlesByUid = $this->pageUidRepository->titlesFor(array_map(static fn (PageTrend $trend): int => $trend->pageUid, $trends));

        return array_map(
            fn (PageTrend $trend): TopPage => new TopPage(
                $trend->pageUid,
                $titlesByUid[$trend->pageUid] ?? sprintf('#%d', $trend->pageUid),
                $this->valueFormatter->format($trend->value, $metric->unit),
                $trend->deltaPercent,
                $trend->direction,
                $metric->polarity,
                $previousPeriodCovered,
                null !== $secondaryMetric ? $this->valueFormatter->format($secondaryValues[$trend->pageUid] ?? 0, $secondaryMetric->unit) : null,
            ),
            $trends,
        );
    }

    /**
     * The second metric's sums for the rows that survived the ranking, which
     * is what keeps the extra column at one query no matter how many pages
     * the site has: the ranking is decided first, then the handful of rows
     * left is asked about. sumsByPage() again rather than a query of its
     * own, so the column costs no new SQL shape to maintain.
     *
     * Only the ranked period. The column states conversions and compares
     * nothing, so it needs no second call for the previous one, and cannot
     * claim a comparison against a period the import never covered.
     *
     * @param list<PageTrend> $trends
     *
     * @return array<int, int>
     */
    private function secondaryValues(array $trends, int $languageId, DateRange $currentRange, ?MetricDefinition $secondaryMetric): array
    {
        if (null === $secondaryMetric || [] === $trends) {
            return [];
        }

        return $this->dailyMetricsRepository->sumsByPage(
            array_map(static fn (PageTrend $trend): int => $trend->pageUid, $trends),
            $languageId,
            $secondaryMetric->identifier,
            $currentRange,
        );
    }

    /**
     * The grouped counterpart of secondaryValues(): a ranked row is a whole
     * subtree here, so the second metric rolls up into its anchor the same
     * way the ranked one does.
     *
     * @param list<PageTrend>       $trends
     * @param array<int, list<int>> $pageUidsByAnchor
     *
     * @return array<int, int>
     */
    private function secondaryValuesGrouped(array $trends, array $pageUidsByAnchor, int $languageId, DateRange $currentRange, ?MetricDefinition $secondaryMetric): array
    {
        if (null === $secondaryMetric || [] === $trends) {
            return [];
        }

        $rankedByAnchor = [];
        foreach ($trends as $trend) {
            $rankedByAnchor[$trend->pageUid] = $pageUidsByAnchor[$trend->pageUid] ?? [];
        }

        $sums = $this->dailyMetricsRepository->sumsByPage($this->flattenAnchorPageUids($rankedByAnchor), $languageId, $secondaryMetric->identifier, $currentRange);

        $values = [];
        foreach ($rankedByAnchor as $anchorUid => $pageUids) {
            $values[$anchorUid] = $this->sumForPages($pageUids, $sums);
        }

        return $values;
    }
}
