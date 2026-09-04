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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricUnit, TrendDirection};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, RateMetricDefinition, TopPage};
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

        return $this->toTopPages(array_slice($trends, 0, $limit), $metric->unit);
    }

    /**
     * @param list<int> $pageUids
     *
     * @return list<TopPage>
     */
    public function rankedByTrend(array $pageUids, int $languageId, MetricDefinition|RateMetricDefinition $metric, DateRange $currentRange, DateRange $previousRange, TrendDirection $direction, int $limit): array
    {
        $trends = $this->computeTrends($pageUids, $languageId, $metric, $currentRange, $previousRange);
        $trends = array_filter($trends, static fn (array $trend): bool => null !== $trend['deltaPercent'] && $trend['trend'] === $direction);

        usort(
            $trends,
            TrendDirection::Down === $direction
                ? static fn (array $a, array $b): int => $a['deltaPercent'] <=> $b['deltaPercent']
                : static fn (array $a, array $b): int => $b['deltaPercent'] <=> $a['deltaPercent'],
        );

        return $this->toTopPages(array_slice($trends, 0, $limit), $metric->unit);
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

        return $this->toTopPages(array_slice($trends, 0, $limit), $metric->unit);
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

            [$deltaPercent, $trend] = $this->computeTrend($value, $previousValue);
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

            [$deltaPercent, $trend] = $this->computeTrend($value, $previousValue);
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

            $value = $this->computeRatio($currentNumerator[$pageUid] ?? 0, $denominator, $metric->unit);
            $previousValue = $this->computeRatio($previousNumerator[$pageUid] ?? 0, $previousDenominatorValue, $metric->unit);

            [$deltaPercent, $trend] = $this->computeTrend($value, $previousValue);
            $trends[] = ['pageUid' => $pageUid, 'value' => $value, 'deltaPercent' => $deltaPercent, 'trend' => $trend];
        }

        return $trends;
    }

    /**
     * @return array{0: ?int, 1: TrendDirection}
     */
    private function computeTrend(int $value, int $previousValue): array
    {
        if (0 === $previousValue) {
            return [null, $value > 0 ? TrendDirection::Up : TrendDirection::Flat];
        }

        $deltaPercent = (int) round(($value - $previousValue) / $previousValue * 100);
        $trend = match (true) {
            $value > $previousValue => TrendDirection::Up,
            $value < $previousValue => TrendDirection::Down,
            default => TrendDirection::Flat,
        };

        return [$deltaPercent, $trend];
    }

    private function computeRatio(int $numerator, int $denominator, MetricUnit $unit): int
    {
        if (0 === $denominator) {
            return 0;
        }

        $ratio = $numerator / $denominator;

        return MetricUnit::Percent === $unit ? (int) round($ratio * 100) : (int) round($ratio);
    }

    /**
     * @param list<array{pageUid: int, value: int, deltaPercent: ?int, trend: TrendDirection}> $trends
     *
     * @return list<TopPage>
     */
    private function toTopPages(array $trends, MetricUnit $unit): array
    {
        $titlesByUid = $this->pageUidRepository->titlesFor(array_map(static fn (array $trend): int => $trend['pageUid'], $trends));

        return array_map(
            fn (array $trend): TopPage => new TopPage(
                $trend['pageUid'],
                $titlesByUid[$trend['pageUid']] ?? sprintf('#%d', $trend['pageUid']),
                $this->formatValue($trend['value'], $unit),
                $trend['deltaPercent'],
                $trend['trend'],
            ),
            $trends,
        );
    }

    private function formatValue(int $value, MetricUnit $unit): string
    {
        return match ($unit) {
            MetricUnit::Percent => sprintf('%d%%', $value),
            MetricUnit::Seconds => sprintf('%d:%02d', intdiv($value, 60), $value % 60),
            MetricUnit::Count => (string) $value,
        };
    }
}
