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
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, MetricTrend, RateMetricDefinition, TopPage};
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
     * @param list<int> $pageUids
     *
     * @return list<TopPage>
     */
    public function rankedByValue(array $pageUids, int $languageId, MetricDefinition|RateMetricDefinition $metric, DateRange $currentRange, DateRange $previousRange, int $limit): array
    {
        $trends = $this->computeTrends($pageUids, $languageId, $metric, $currentRange, $previousRange);
        usort($trends, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return $this->toTopPages(array_slice($trends, 0, $limit), $metric);
    }

    /**
     * @param list<int> $pageUids
     *
     * @return list<TopPage>
     */
    public function rankedByTrend(array $pageUids, int $languageId, MetricDefinition|RateMetricDefinition $metric, DateRange $currentRange, DateRange $previousRange, TrendSentiment $sentiment, int $limit): array
    {
        $polarity = $metric->polarity;
        $trends = $this->computeTrends($pageUids, $languageId, $metric, $currentRange, $previousRange);
        $trends = array_filter(
            $trends,
            static fn (array $trend): bool => null !== $trend['deltaPercent']
                && $sentiment === self::rankingSentimentOf($trend['trend'], $polarity),
        );

        // Ranked by how far the change went in the asked-for direction, not
        // by the raw percentage: for a metric where lower is better, the
        // worst drops are the biggest *rises*, so the sign has to be
        // normalised before sorting.
        usort(
            $trends,
            TrendSentiment::Negative === $sentiment
                ? static fn (array $a, array $b): int => self::signedDelta($a['deltaPercent'], $polarity) <=> self::signedDelta($b['deltaPercent'], $polarity)
                : static fn (array $a, array $b): int => self::signedDelta($b['deltaPercent'], $polarity) <=> self::signedDelta($a['deltaPercent'], $polarity),
        );

        return $this->toTopPages(array_slice($trends, 0, $limit), $metric);
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
    public function rankedByValueGrouped(array $pageUidsByAnchor, int $languageId, MetricDefinition $metric, DateRange $currentRange, DateRange $previousRange, int $limit): array
    {
        $trends = $this->computeGroupedTrends($pageUidsByAnchor, $languageId, $metric->identifier, $currentRange, $previousRange);
        usort($trends, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return $this->toTopPages(array_slice($trends, 0, $limit), $metric);
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
     * $deltaPercent is never actually null here: rankedByTrend() filters
     * those out before sorting, since a page with no comparable previous
     * period has no percentage to rank by in the first place.
     */
    private static function signedDelta(?int $deltaPercent, MetricPolarity $polarity): int
    {
        return MetricPolarity::LowerIsBetter === $polarity ? -(int) $deltaPercent : (int) $deltaPercent;
    }

    /**
     * @param list<int> $pageUids
     *
     * @return list<array{pageUid: int, value: int, deltaPercent: ?int, trend: TrendDirection}>
     */
    private function computeTrends(array $pageUids, int $languageId, MetricDefinition|RateMetricDefinition $metric, DateRange $currentRange, DateRange $previousRange): array
    {
        return $metric instanceof MetricDefinition
            ? $this->computePageTrends($pageUids, $languageId, $metric->identifier, $currentRange, $previousRange)
            : $this->computePageTrendsForRate($pageUids, $languageId, $metric, $currentRange, $previousRange);
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
     * @return list<array{pageUid: int, value: int, deltaPercent: ?int, trend: TrendDirection}>
     */
    private function computePageTrends(array $pageUids, int $languageId, string $metric, DateRange $currentRange, DateRange $previousRange): array
    {
        $currentSums = $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric, $currentRange);
        $previousSums = $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric, $previousRange);

        $trends = [];
        foreach (array_unique([...array_keys($currentSums), ...array_keys($previousSums)]) as $pageUid) {
            $value = $currentSums[$pageUid] ?? 0;
            $previousValue = $previousSums[$pageUid] ?? 0;

            if (0 === $value && 0 === $previousValue) {
                continue;
            }

            $metricTrend = MetricTrend::between($value, $previousValue);
            $deltaPercent = $metricTrend->deltaPercent;
            $trend = $metricTrend->direction;
            $trends[] = ['pageUid' => $pageUid, 'value' => $value, 'deltaPercent' => $deltaPercent, 'trend' => $trend];
        }

        return $trends;
    }

    /**
     * @param array<int, list<int>> $pageUidsByAnchor
     *
     * @return list<array{pageUid: int, value: int, deltaPercent: ?int, trend: TrendDirection}>
     */
    private function computeGroupedTrends(array $pageUidsByAnchor, int $languageId, string $metric, DateRange $currentRange, DateRange $previousRange): array
    {
        $allPageUids = $this->flattenAnchorPageUids($pageUidsByAnchor);
        $currentSums = $this->dailyMetricsRepository->sumsByPage($allPageUids, $languageId, $metric, $currentRange);
        $previousSums = $this->dailyMetricsRepository->sumsByPage($allPageUids, $languageId, $metric, $previousRange);

        $trends = [];
        foreach ($pageUidsByAnchor as $anchorUid => $pageUids) {
            $value = $this->sumForPages($pageUids, $currentSums);
            $previousValue = $this->sumForPages($pageUids, $previousSums);

            if (0 === $value && 0 === $previousValue) {
                continue;
            }

            $metricTrend = MetricTrend::between($value, $previousValue);
            $deltaPercent = $metricTrend->deltaPercent;
            $trend = $metricTrend->direction;
            $trends[] = ['pageUid' => $anchorUid, 'value' => $value, 'deltaPercent' => $deltaPercent, 'trend' => $trend];
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
     * @return list<array{pageUid: int, value: int, deltaPercent: ?int, trend: TrendDirection}>
     */
    private function computePageTrendsForRate(array $pageUids, int $languageId, RateMetricDefinition $metric, DateRange $currentRange, DateRange $previousRange): array
    {
        $currentNumerator = $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric->numerator, $currentRange);
        $currentDenominator = $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric->denominator, $currentRange);
        $previousNumerator = $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric->numerator, $previousRange);
        $previousDenominator = $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric->denominator, $previousRange);

        $trends = [];
        foreach (array_unique([...array_keys($currentDenominator), ...array_keys($previousDenominator)]) as $pageUid) {
            $denominator = $currentDenominator[$pageUid] ?? 0;
            $previousDenominatorValue = $previousDenominator[$pageUid] ?? 0;

            if (0 === $denominator && 0 === $previousDenominatorValue) {
                continue;
            }

            $value = $this->rateSeriesCalculator->ratio($currentNumerator[$pageUid] ?? 0, $denominator, $metric->unit);
            $previousValue = $this->rateSeriesCalculator->ratio($previousNumerator[$pageUid] ?? 0, $previousDenominatorValue, $metric->unit);

            $metricTrend = MetricTrend::between($value, $previousValue);
            $deltaPercent = $metricTrend->deltaPercent;
            $trend = $metricTrend->direction;
            $trends[] = ['pageUid' => $pageUid, 'value' => $value, 'deltaPercent' => $deltaPercent, 'trend' => $trend];
        }

        return $trends;
    }

    /**
     * @param list<array{pageUid: int, value: int, deltaPercent: ?int, trend: TrendDirection}> $trends
     *
     * @return list<TopPage>
     */
    private function toTopPages(array $trends, MetricDefinition|RateMetricDefinition $metric): array
    {
        $titlesByUid = $this->pageUidRepository->titlesFor(array_map(static fn (array $trend): int => $trend['pageUid'], $trends));

        return array_map(
            fn (array $trend): TopPage => new TopPage(
                $trend['pageUid'],
                $titlesByUid[$trend['pageUid']] ?? sprintf('#%d', $trend['pageUid']),
                $this->valueFormatter->format($trend['value'], $metric->unit),
                $trend['deltaPercent'],
                $trend['trend'],
                $metric->polarity,
            ),
            $trends,
        );
    }
}
