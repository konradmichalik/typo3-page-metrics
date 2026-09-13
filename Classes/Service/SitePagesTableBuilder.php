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

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, MetricTrend, RateMetricDefinition, SitePagesCell, SitePagesColumn, SitePagesRow, SitePagesTable};
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, SitePageUidRepository};
use KonradMichalik\Typo3PageMetrics\Provider\{MetricDefinitionCollector, MetricProviderInterface};
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function ceil;
use function count;
use function in_array;
use function max;
use function min;
use function sprintf;
use function usort;

/**
 * SitePagesTableBuilder.
 *
 * Breadth where PageMetricsViewDataBuilder gives depth: every page of one
 * site side by side, one column per metric, sorted by whichever column was
 * asked for. The page module and the module's own page view answer for a
 * single page, and nothing before this answered "which pages of this site
 * are worth my attention".
 *
 * Every figure comes out of DailyMetricsRepository::sumsByPage(), one call
 * per metric over the whole set of pages, so the cost of a render is set by
 * how many metrics the provider declares and not by how many pages the site
 * has. Nothing here asks a question per row: a site-wide table is exactly
 * the place where a per-row query becomes an N+1 over a whole site.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SitePagesTableBuilder
{
    /**
     * @param iterable<MetricProviderInterface> $providers
     */
    public function __construct(
        private SitePageUidRepository $pageUidRepository,
        private DailyMetricsRepository $dailyMetricsRepository,
        private RateSeriesCalculator $rateSeriesCalculator,
        private MetricValueFormatter $valueFormatter,
        private ImportCoverage $importCoverage,
        private MetricDefinitionCollector $metricDefinitionCollector,
        private iterable $providers,
    ) {}

    /**
     * $sortKey is a metric identifier; anything else, including the empty
     * string a reader who has never sorted arrives with, falls back to the
     * site's first metric. Only metric columns sort: ordering by page title
     * would need every title of the site loaded to order by, where a metric
     * column orders by figures that are in memory already.
     *
     * $pageNumber is one-based and clamped into range, so a stale bookmark
     * lands on the last page rather than on an empty table that reads as
     * "this site has no pages".
     */
    public function build(Site $site, int $languageId, int $periodDays, string $sortKey = '', string $sortDirection = '', int $pageNumber = 1): SitePagesTable
    {
        $provider = $this->resolveProvider($site);
        $metrics = null !== $provider ? $this->metricsFor($provider, $site) : [];

        if (null === $provider || [] === $metrics) {
            return SitePagesTable::withoutProvider();
        }

        $sortMetric = $this->resolveSortMetric($metrics, $sortKey);
        $sortDirection = in_array($sortDirection, [SitePagesTable::DIRECTION_ASCENDING, SitePagesTable::DIRECTION_DESCENDING], true)
            ? $sortDirection
            : SitePagesTable::DIRECTION_DESCENDING;

        [$currentRange, $previousRange] = $this->computeRanges($periodDays);
        $pageUids = $this->readablePageUids($site);
        $previousPeriodCovered = $this->importCoverage->covers(
            $this->importCoverage->forProvider($provider->getIdentifier(), $site->getIdentifier()),
            $previousRange,
        );

        $currentValues = [];
        foreach ($metrics as $metric) {
            $currentValues[$metric->identifier] = $this->valuesFor($metric, $pageUids, $languageId, $currentRange);
        }

        // Only the sorted metric is asked about twice. The trend column
        // states how the column the table is ordered by moved, so a second
        // pass over every metric would buy nothing the table shows. Not
        // asked for at all outside the imported window, where the sum would
        // be made of days that were never fetched.
        $previousValues = $previousPeriodCovered
            ? $this->valuesFor($sortMetric, $pageUids, $languageId, $previousRange)
            : [];

        $sortValues = [];
        foreach ($this->rowPageUids($currentValues, $previousValues) as $rowPageUid) {
            $sortValues[$rowPageUid] = self::valueOf($sortMetric, $currentValues[$sortMetric->identifier], $rowPageUid);
        }

        $rowPageUids = $this->sorted(array_keys($sortValues), $sortValues, $sortDirection);
        $totalRows = count($rowPageUids);
        $pageNumber = max(1, min($pageNumber, (int) max(1, ceil($totalRows / SitePagesTable::PAGE_SIZE))));
        $visiblePageUids = array_slice($rowPageUids, ($pageNumber - 1) * SitePagesTable::PAGE_SIZE, SitePagesTable::PAGE_SIZE);

        return new SitePagesTable(
            true,
            $this->columnsFor($metrics, $sortMetric->identifier, $sortDirection),
            $this->rowsFor($visiblePageUids, $metrics, $sortMetric, $currentValues, $previousValues, $previousPeriodCovered),
            $sortMetric->identifier,
            $sortDirection,
            $sortMetric->label,
            self::formatRangeLabel($currentRange),
            self::formatRangeLabel($previousRange),
            $totalRows,
            $pageNumber,
            $previousPeriodCovered,
        );
    }

    /**
     * The pages of this site the current backend user is allowed to see.
     *
     * Filtered rather than shown in full, and that is a security decision
     * rather than a display one: a site-wide table is the one surface of
     * this extension that would put the title and the traffic of a page an
     * editor cannot open into their browser. Every other surface checks
     * PAGE_SHOW on the page it is about (see PageMetricsViewDataBuilder),
     * and a table that skipped the check would be the way around all of
     * them.
     *
     * Filtering costs this view nothing in coherence, because it reports no
     * total: every figure in the table belongs to a row, so leaving a row
     * out cannot make a sum disagree with the rows it is made of. That is
     * the half of the question the spec leaves open for the CSV export
     * (§8.4), and it is open there precisely because an export can be
     * aggregated afterwards.
     *
     * @return list<int>
     */
    private function readablePageUids(Site $site): array
    {
        return $this->pageUidRepository->readableUids(
            $this->pageUidRepository->descendantsOf($site->getRootPageId()),
            $this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW),
        );
    }

    /**
     * Which pages get a row: every page that recorded anything at all in
     * this period, plus every page that recorded the sorted metric in the
     * period before and nothing now. The second half is the whole reason
     * this view exists: a page that quietly stopped earning its traffic
     * would otherwise be invisible in the one table meant to find it.
     *
     * @param array<string, array<int, int>> $currentValues
     * @param array<int, int>                $previousValues
     *
     * @return list<int>
     */
    private function rowPageUids(array $currentValues, array $previousValues): array
    {
        $pageUids = [];

        foreach ([...array_values($currentValues), $previousValues] as $values) {
            foreach (array_keys($values) as $pageUid) {
                $pageUids[$pageUid] = true;
            }
        }

        return array_keys($pageUids);
    }

    /**
     * @param list<int>        $pageUids
     * @param array<int, ?int> $sortValues
     *
     * @return list<int>
     */
    private function sorted(array $pageUids, array $sortValues, string $sortDirection): array
    {
        usort($pageUids, static function (int $a, int $b) use ($sortValues, $sortDirection): int {
            $aValue = $sortValues[$a] ?? null;
            $bValue = $sortValues[$b] ?? null;

            if (null === $aValue || null === $bValue) {
                // A page the metric was never recorded for goes last
                // whichever way the column points. It does not hold the
                // lowest value, it holds none, and leading an ascending
                // sort with a screen of blanks answers nothing.
                return [null === $aValue, $a] <=> [null === $bValue, $b];
            }

            // The page uid breaks every tie, so the order is total. Without
            // that, two pages on the same value could swap places between
            // one page of the table and the next, and a row would be shown
            // twice or not at all.
            return SitePagesTable::DIRECTION_ASCENDING === $sortDirection
                ? [$aValue, $a] <=> [$bValue, $b]
                : [$bValue, $a] <=> [$aValue, $b];
        });

        return $pageUids;
    }

    /**
     * @param list<MetricDefinition|RateMetricDefinition> $metrics
     *
     * @return list<SitePagesColumn>
     */
    private function columnsFor(array $metrics, string $sortKey, string $sortDirection): array
    {
        return array_map(
            static fn (MetricDefinition|RateMetricDefinition $metric): SitePagesColumn => new SitePagesColumn(
                $metric->identifier,
                $metric->label,
                $metric->description,
                $metric->identifier === $sortKey,
                $sortDirection,
            ),
            $metrics,
        );
    }

    /**
     * @param list<int>                                   $pageUids
     * @param list<MetricDefinition|RateMetricDefinition> $metrics
     * @param array<string, array<int, int>>              $currentValues
     * @param array<int, int>                             $previousValues
     *
     * @return list<SitePagesRow>
     */
    private function rowsFor(array $pageUids, array $metrics, MetricDefinition|RateMetricDefinition $sortMetric, array $currentValues, array $previousValues, bool $previousPeriodCovered): array
    {
        // One title lookup for the rows actually on screen, after the sort
        // has decided which those are, the same order as
        // PageTrendCalculator::toTopPages(), and what keeps the title
        // column at one query no matter how long the site is.
        $titles = $this->pageUidRepository->titlesFor($pageUids);
        $rows = [];

        foreach ($pageUids as $pageUid) {
            $cells = [];
            foreach ($metrics as $metric) {
                $value = self::valueOf($metric, $currentValues[$metric->identifier], $pageUid);
                $cells[] = new SitePagesCell(null !== $value, null !== $value ? $this->valueFormatter->format($value, $metric->unit) : '');
            }

            $trend = $previousPeriodCovered
                ? MetricTrend::between(
                    self::valueOf($sortMetric, $currentValues[$sortMetric->identifier], $pageUid) ?? 0,
                    self::valueOf($sortMetric, $previousValues, $pageUid) ?? 0,
                )
                : MetricTrend::none();

            $rows[] = new SitePagesRow(
                $pageUid,
                $titles[$pageUid] ?? sprintf('#%d', $pageUid),
                $cells,
                $trend->deltaPercent,
                $trend->direction,
                $sortMetric->polarity,
                $previousPeriodCovered,
            );
        }

        return $rows;
    }

    /**
     * Null only where the metric was never recorded for this page and the
     * absence is not a zero. A count with no stored row is a real zero,
     * because the importers write no row for a page that had no traffic
     * (see TopPage::$secondaryFormattedValue for the same reading). A rate
     * with no denominator is a ratio nothing was divided by, which is not a
     * flattering zero but no value at all.
     *
     * @param array<int, int> $values
     */
    private static function valueOf(MetricDefinition|RateMetricDefinition $metric, array $values, int $pageUid): ?int
    {
        if (array_key_exists($pageUid, $values)) {
            return $values[$pageUid];
        }

        return $metric instanceof MetricDefinition ? 0 : null;
    }

    /**
     * @param list<int> $pageUids
     *
     * @return array<int, int> page uid => value, only for the pages the metric was recorded for
     */
    private function valuesFor(MetricDefinition|RateMetricDefinition $metric, array $pageUids, int $languageId, DateRange $range): array
    {
        if ($metric instanceof MetricDefinition) {
            return $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric->identifier, $range);
        }

        // A rate is not summable across days, so it is sum(numerator) over
        // sum(denominator) for the whole period, exactly as
        // PageTrendCalculator computes it for a ranked row.
        $numerators = $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric->numerator, $range);
        $denominators = $this->dailyMetricsRepository->sumsByPage($pageUids, $languageId, $metric->denominator, $range);

        $values = [];
        foreach ($denominators as $pageUid => $denominator) {
            $values[$pageUid] = $this->rateSeriesCalculator->ratio($numerators[$pageUid] ?? 0, $denominator, $metric->unit);
        }

        return $values;
    }

    /**
     * Every metric this site has, counts and rates alike, in the order the
     * provider declares them, so the columns read left to right the same
     * way the page-header tiles read.
     *
     * @return list<MetricDefinition|RateMetricDefinition>
     */
    private function metricsFor(MetricProviderInterface $provider, Site $site): array
    {
        $metrics = [...$this->metricDefinitionCollector->forSite($provider, $site), ...$provider->getRateMetricDefinitions()];
        usort($metrics, static fn (MetricDefinition|RateMetricDefinition $a, MetricDefinition|RateMetricDefinition $b): int => $a->sortOrder <=> $b->sortOrder);

        return $metrics;
    }

    /**
     * @param non-empty-list<MetricDefinition|RateMetricDefinition> $metrics
     */
    private function resolveSortMetric(array $metrics, string $sortKey): MetricDefinition|RateMetricDefinition
    {
        foreach ($metrics as $metric) {
            if ($metric->identifier === $sortKey) {
                return $metric;
            }
        }

        // The site's first metric, which is its own "page views" by
        // convention (see SiteMetricsOverviewBuilder::primaryDefinition()):
        // a table nobody has sorted yet opens on the figure the rest of the
        // extension leads with.
        return $metrics[0];
    }

    private function resolveProvider(Site $site): ?MetricProviderInterface
    {
        return $this->metricDefinitionCollector->supporting($this->providers, $site)[0] ?? null;
    }

    /**
     * @return array{0: DateRange, 1: DateRange}
     */
    private function computeRanges(int $periodDays): array
    {
        $today = new DateTimeImmutable('today');
        $currentRange = new DateRange($today->modify(sprintf('-%d days', $periodDays - 1)), $today);
        $previousUntil = $currentRange->from->modify('-1 day');

        return [$currentRange, new DateRange($previousUntil->modify(sprintf('-%d days', $periodDays - 1)), $previousUntil)];
    }

    private static function formatRangeLabel(DateRange $range): string
    {
        return sprintf('%s to %s', $range->from->format('Y-m-d'), $range->until->format('Y-m-d'));
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
