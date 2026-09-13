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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\TrendSentiment;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, MetricSignificance, MetricTrend, RateMetricDefinition, RateTotals, SitePagesCell, SitePagesColumn, SitePagesDataSet, SitePagesExportRow, SitePagesRow, SitePagesTable};
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, SitePageUidRepository};
use KonradMichalik\Typo3PageMetrics\Provider\{MetricDefinitionCollector, MetricProviderInterface};
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

use function array_chunk;
use function array_filter;
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
        private RateMetricReader $rateMetricReader,
        private MetricValueFormatter $valueFormatter,
        private ImportCoverage $importCoverage,
        private MetricDefinitionCollector $metricDefinitionCollector,
        private iterable $providers,
    ) {}

    /**
     * $sortKey is a metric identifier or SitePagesTable::SORT_DECAY; anything
     * else, including the empty string a reader who has never sorted arrives
     * with, falls back to the site's first metric. Nothing else sorts:
     * ordering by page title would need every title of the site loaded to
     * order by, where a metric column orders by figures that are in memory
     * already.
     *
     * $pageNumber is one-based and clamped into range, so a stale bookmark
     * lands on the last page rather than on an empty table that reads as
     * "this site has no pages".
     */
    public function build(Site $site, int $languageId, int $periodDays, string $sortKey = '', string $sortDirection = '', int $pageNumber = 1): SitePagesTable
    {
        $dataSet = $this->dataSetFor($site, $languageId, $periodDays, $sortKey, $sortDirection);

        if (null === $dataSet) {
            return SitePagesTable::withoutProvider();
        }

        $totalRows = count($dataSet->pageUids);
        $pageNumber = max(1, min($pageNumber, (int) max(1, ceil($totalRows / SitePagesTable::PAGE_SIZE))));
        $visiblePageUids = array_slice($dataSet->pageUids, ($pageNumber - 1) * SitePagesTable::PAGE_SIZE, SitePagesTable::PAGE_SIZE);

        return new SitePagesTable(
            true,
            $this->columnsFor($dataSet->metrics, $dataSet->sortKey, $dataSet->sortDirection),
            $this->rowsFor($visiblePageUids, $dataSet),
            $dataSet->sortKey,
            $dataSet->sortDirection,
            $dataSet->sortMetric->label,
            self::formatRangeLabel($dataSet->currentRange),
            self::formatRangeLabel($dataSet->previousRange),
            $totalRows,
            $pageNumber,
            $dataSet->previousPeriodCovered,
            SitePagesColumn::forDecay(SitePagesTable::SORT_DECAY === $dataSet->sortKey, $dataSet->sortDirection),
        );
    }

    /**
     * The same rows build() would show, all of them, with the numbers rather
     * than the words for them. This is what the CSV export writes.
     *
     * Same ordering, same period, same PAGE_SHOW filter, and all three
     * because they come out of the same dataSetFor() call the view uses
     * rather than from a second implementation that would be free to drift.
     * An export is only "what the view shows" for as long as nobody gives it
     * an opinion of its own.
     *
     * Yields in blocks rather than returning a list, because an export is
     * the one surface of this extension with no upper bound on rows: a site
     * with twenty thousand pages would otherwise hold twenty thousand row
     * objects and every title of the site in memory at once, on top of the
     * sums the ordering already needs. The block is the page size the table
     * uses, so the title lookup stays one query per block exactly as it is
     * for a rendered screen.
     *
     * The column keys of $values, and the unit each is counted in, are what
     * columnsOf() returns.
     *
     * @return iterable<SitePagesExportRow>
     */
    public function stream(Site $site, int $languageId, int $periodDays, string $sortKey = '', string $sortDirection = ''): iterable
    {
        $dataSet = $this->dataSetFor($site, $languageId, $periodDays, $sortKey, $sortDirection);

        if (null === $dataSet) {
            return;
        }

        foreach (array_chunk($dataSet->pageUids, SitePagesTable::PAGE_SIZE) as $block) {
            $titles = $this->pageUidRepository->titlesFor($block);

            foreach ($block as $pageUid) {
                yield $this->exportRowFor($pageUid, $titles[$pageUid] ?? sprintf('#%d', $pageUid), $dataSet);
            }
        }
    }

    /**
     * The columns a row of stream() is keyed by, with the unit each raw
     * value is counted in, and nothing about sorting: an export header names
     * a column, it does not offer to reorder one.
     *
     * Cheap on purpose, so a file can be written without building a screen
     * first: this resolves the site's provider and its metric definitions
     * and asks the database nothing.
     *
     * @return list<SitePagesColumn>
     */
    public function columnsOf(Site $site): array
    {
        $provider = $this->resolveProvider($site);

        return null !== $provider ? $this->columnsFor($this->metricsFor($provider, $site), '', SitePagesTable::DIRECTION_DESCENDING) : [];
    }

    private function exportRowFor(int $pageUid, string $title, SitePagesDataSet $dataSet): SitePagesExportRow
    {
        $values = [];
        foreach ($dataSet->metrics as $metric) {
            $values[$metric->identifier] = self::valueOf($metric, $dataSet->currentValues[$metric->identifier], $pageUid);
        }

        return new SitePagesExportRow(
            $pageUid,
            $title,
            $values,
            $this->trendFor($pageUid, $dataSet)->deltaPercent,
            $dataSet->previousPeriodCovered,
            $dataSet->ages[$pageUid] ?? null,
            $dataSet->decaying[$pageUid] ?? false,
        );
    }

    /**
     * One pass over the site: which pages the reader may see, what each of
     * them recorded, how long ago each was changed, which of them are
     * decaying, and the single order all of that puts them in.
     *
     * Null where the site configured no metric provider at all, which is an
     * answer about the site rather than an empty result.
     */
    private function dataSetFor(Site $site, int $languageId, int $periodDays, string $sortKey, string $sortDirection): ?SitePagesDataSet
    {
        $provider = $this->resolveProvider($site);
        $metrics = null !== $provider ? $this->metricsFor($provider, $site) : [];

        if (null === $provider || [] === $metrics) {
            return null;
        }

        // "decay" is not a metric, so resolveSortMetric() falls back to the
        // site's first one, and that is exactly the metric a decay verdict
        // has to be made on: the count every provider leads with. The trend
        // column therefore keeps showing the fall the verdict was made from,
        // which is half of what a reader needs to see why a row is where it
        // is. The other half is the age, in its own column beside it.
        $decaySort = SitePagesTable::SORT_DECAY === $sortKey;
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

        // Asked for the whole readable set and not only the visible page,
        // because the age is one of the two things the decay order sorts by
        // and a sort cannot be made from the rows it has already chosen. Two
        // queries for the set, the same shape as the metric sums above.
        $ages = $this->agesOf($this->pageUidRepository->lastChangedAt($pageUids), $currentRange->until);

        $sortValues = [];
        $decaying = [];
        foreach ($this->rowPageUids($currentValues, $previousValues) as $rowPageUid) {
            $sortValues[$rowPageUid] = self::valueOf($sortMetric, $currentValues[$sortMetric->identifier], $rowPageUid);
            // Withheld wholesale outside the imported window, for the same
            // reason the trend is: a verdict built on a sum of days nobody
            // fetched is a claim about the import, not about the page.
            $decaying[$rowPageUid] = $previousPeriodCovered && self::isDecaying(
                $sortMetric,
                $sortValues[$rowPageUid] ?? 0,
                self::valueOf($sortMetric, $previousValues, $rowPageUid) ?? 0,
                $ages[$rowPageUid] ?? null,
                $periodDays,
            );
        }

        return new SitePagesDataSet(
            $metrics,
            $sortMetric,
            $decaySort ? SitePagesTable::SORT_DECAY : $sortMetric->identifier,
            $sortDirection,
            $currentRange,
            $previousRange,
            $previousPeriodCovered,
            $currentValues,
            $previousValues,
            $ages,
            $decaying,
            $decaySort
                ? $this->sortedByDecay(array_keys($sortValues), $decaying, $ages, $sortDirection)
                : $this->sorted(array_keys($sortValues), $sortValues, $sortDirection),
        );
    }

    /**
     * How the sorted metric moved for this page, or nothing at all where the
     * period before was never imported (see ImportCoverage::covers()).
     */
    private function trendFor(int $pageUid, SitePagesDataSet $dataSet): MetricTrend
    {
        if (!$dataSet->previousPeriodCovered) {
            return MetricTrend::none();
        }

        return MetricTrend::between(
            self::valueOf($dataSet->sortMetric, $dataSet->currentValues[$dataSet->sortMetric->identifier], $pageUid) ?? 0,
            self::valueOf($dataSet->sortMetric, $dataSet->previousValues, $pageUid) ?? 0,
        );
    }

    /**
     * Whether this page is decaying: it lost the metric the table judges on,
     * by enough to clear that metric's own MetricSignificance, and nobody
     * has touched it since before the two compared periods began.
     *
     * There is no calendar threshold here, and that is the decision. §8.2 of
     * the module spec proposes 180 days and says so is a proposal rather
     * than a measurement; a sort does not need one at all. The list is
     * ordered by age with the age written in its own column, so a reader
     * draws the line where their own site's editing rhythm puts it instead
     * of inheriting a number nobody measured. What is left is the only
     * boundary the data itself supplies: a page edited inside the window the
     * fall was measured in did not fade, it was changed, and a change with
     * an effect is a different finding from neglect.
     *
     * Falling is expressed as TrendSentiment::Negative rather than as a
     * downward direction, so a provider whose first metric is one where
     * lower is better does not get its improvements filed as decay.
     *
     * A page that never had this metric is never decaying, and that falls
     * out of the shared rule rather than being restated here:
     * MetricSignificance::clears() refuses a previous value of zero, because
     * there is no share of nothing. A page nobody ever found is not fading,
     * and it needs a different kind of attention.
     */
    private static function isDecaying(MetricDefinition|RateMetricDefinition $metric, int $value, int $previousValue, ?int $daysSinceChange, int $periodDays): bool
    {
        if (null === $daysSinceChange || $daysSinceChange < 2 * $periodDays) {
            return false;
        }

        $sentiment = TrendSentiment::of(MetricTrend::between($value, $previousValue)->direction, $metric->polarity);

        return TrendSentiment::Negative === $sentiment
            && ($metric->significance ?? MetricSignificance::default())->clears($value, $previousValue);
    }

    /**
     * Decaying pages first, and the longest untouched first among them.
     *
     * Two keys rather than a score, which is what makes the order readable:
     * every row above the first undecayed one is falling significantly, and
     * within that block row 1 is above row 2 because nobody has touched it
     * for longer. Both facts are in columns of the table, so the reason a
     * row is where it is can be checked rather than trusted.
     *
     * Age leads over the size of the fall on purpose. The significance rule
     * has already decided the fall is real, and beyond that a steeper fall
     * is not more actionable than a shallower one, while a page nobody has
     * opened in three years is a different proposition from one edited last
     * quarter.
     *
     * @param list<int>        $pageUids
     * @param array<int, bool> $decaying
     * @param array<int, int>  $ages
     *
     * @return list<int>
     */
    private function sortedByDecay(array $pageUids, array $decaying, array $ages, string $sortDirection): array
    {
        usort($pageUids, static function (int $a, int $b) use ($decaying, $ages, $sortDirection): int {
            $aAge = $ages[$a] ?? null;
            $bAge = $ages[$b] ?? null;

            if (null === $aAge || null === $bAge) {
                // A page whose record carries no timestamp has no age, the
                // same reading a metric that was never recorded gets, so it
                // goes last whichever way the column points.
                return [null === $aAge, $a] <=> [null === $bAge, $b];
            }

            // The page uid breaks every tie, so the order is total and a row
            // cannot swap places between one page of the table and the next.
            return SitePagesTable::DIRECTION_ASCENDING === $sortDirection
                ? [$decaying[$a] ?? false, $aAge, $a] <=> [$decaying[$b] ?? false, $bAge, $b]
                : [$decaying[$b] ?? false, $bAge, $a] <=> [$decaying[$a] ?? false, $aAge, $b];
        });

        return $pageUids;
    }

    /**
     * @param array<int, int> $lastChanged page uid => Unix timestamp
     *
     * @return array<int, int> page uid => whole days since that change
     */
    private function agesOf(array $lastChanged, DateTimeImmutable $today): array
    {
        return array_map(
            // Both sides flattened to midnight before the difference is
            // taken, so a page changed late yesterday is one day old and not
            // zero, and the timezone is the one the request runs in rather
            // than the UTC a raw timestamp parses as.
            static fn (int $timestamp): int => (new DateTimeImmutable('@'.$timestamp))
                ->setTimezone($today->getTimezone())
                ->setTime(0, 0)
                ->diff($today)
                ->days,
            // A tstamp of zero is a record nobody wrote a time on, not a
            // change made in 1970, and treating it as an age would put every
            // such page at the head of a list ordered by age.
            array_filter($lastChanged, static fn (int $timestamp): bool => $timestamp > 0),
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
                $metric->unit,
            ),
            $metrics,
        );
    }

    /**
     * @param list<int> $pageUids
     *
     * @return list<SitePagesRow>
     */
    private function rowsFor(array $pageUids, SitePagesDataSet $dataSet): array
    {
        // One title lookup for the rows actually on screen, after the sort
        // has decided which those are, the same order as
        // PageTrendCalculator::toTopPages(), and what keeps the title
        // column at one query no matter how long the site is.
        $titles = $this->pageUidRepository->titlesFor($pageUids);
        $rows = [];

        foreach ($pageUids as $pageUid) {
            $cells = [];
            foreach ($dataSet->metrics as $metric) {
                $value = self::valueOf($metric, $dataSet->currentValues[$metric->identifier], $pageUid);
                $cells[] = new SitePagesCell(null !== $value, null !== $value ? $this->valueFormatter->format($value, $metric->unit) : '');
            }

            $trend = $this->trendFor($pageUid, $dataSet);

            $rows[] = new SitePagesRow(
                $pageUid,
                $titles[$pageUid] ?? sprintf('#%d', $pageUid),
                $cells,
                $trend->deltaPercent,
                $trend->direction,
                $dataSet->sortMetric->polarity,
                $dataSet->previousPeriodCovered,
                $dataSet->ages[$pageUid] ?? null,
                $dataSet->decaying[$pageUid] ?? false,
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
        // PageTrendCalculator computes it for a ranked row. Only the pages
        // the denominator was recorded for come back, which is what
        // valueOf() reads as "this page has a value at all".
        return array_map(
            static fn (RateTotals $totals): int => $totals->ratio,
            $this->rateMetricReader->byPage($pageUids, $languageId, $metric, $range),
        );
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
