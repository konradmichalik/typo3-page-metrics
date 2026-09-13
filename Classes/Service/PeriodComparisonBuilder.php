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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricGroup, MetricUnit, TrendSentiment};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{ComparisonBaseline, DateRange, MetricDefinition, MetricSignificance, MetricTrend, PeriodComparison, PeriodComparisonRow, RateMetricDefinition};
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, SitePageUidRepository};
use KonradMichalik\Typo3PageMetrics\Provider\{MetricDefinitionCollector, MetricProviderInterface};
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

use function abs;
use function array_map;
use function array_sum;
use function sprintf;
use function usort;

/**
 * PeriodComparisonBuilder.
 *
 * One page over two periods of the same length, which is the one question
 * the tiles and their popover cannot carry: not "how is this page doing" but
 * "is this stretch of days actually different from that one".
 *
 * A sibling of PageMetricsViewDataBuilder and DayDetailBuilder rather than a
 * method on either. That one answers "how is this page doing over a period"
 * and always against the days immediately before; this one lets the reader
 * name the period it is held against, which is what makes the view worth
 * opening at all: traffic driven by a yearly event is unremarkable against
 * last month and the whole story against last March.
 *
 * What counts as a real change is MetricSignificance, resolved exactly as
 * PageTrendCalculator resolves it for the trend widget, defaults included.
 * Two surfaces calling different movements "significant" would be worse than
 * neither of them saying the word.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PeriodComparisonBuilder
{
    /**
     * @param iterable<MetricProviderInterface> $providers
     */
    public function __construct(
        private SiteFinder $siteFinder,
        private DailyMetricsRepository $dailyMetricsRepository,
        private SitePageUidRepository $pageUidRepository,
        private RateMetricReader $rateMetricReader,
        private ImportCoverage $importCoverage,
        private MetricValueFormatter $valueFormatter,
        private MetricDefinitionCollector $metricDefinitionCollector,
        private iterable $providers,
    ) {}

    /**
     * $baselineKey arrives as the raw `moduleData` value rather than as a
     * ComparisonBaseline, so the caller stores a reader's choice and this
     * class stays the only place that decides what an unknown one means.
     *
     * Null for anything this backend user may not see, the same decision
     * PageMetricsViewDataBuilder and DayDetailBuilder make and for the same
     * reason: the page uid arrives from the request, so the permission check
     * is what stands between a hand-built URL and another page's numbers.
     */
    public function build(int $pageId, int $languageId, int $periodDays, ?string $baselineKey, bool $includeSubpages = false): ?PeriodComparison
    {
        if ($pageId < 1) {
            return null;
        }

        if (false === BackendUtility::readPageAccess($pageId, $this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW))) {
            return null;
        }

        $site = $this->resolveSite($pageId);

        if (null === $site) {
            return null;
        }

        $baseline = ComparisonBaseline::fromKey($baselineKey);
        // The same arithmetic PageMetricsViewDataBuilder uses for its tiles,
        // so "this period" names the same days in both views of one module.
        $today = new DateTimeImmutable('today');
        $current = new DateRange($today->modify(sprintf('-%d days', $periodDays - 1)), $today);
        $reference = $baseline->reference($current, $periodDays);
        $pageUids = $includeSubpages ? $this->pageUidRepository->descendantsOf($pageId) : [$pageId];

        return new PeriodComparison(
            $baseline,
            $periodDays,
            self::rangeLabel($current),
            self::rangeLabel($reference),
            $this->rows($site, $pageUids, $languageId, $current, $reference),
            ComparisonBaseline::all(),
        );
    }

    private static function rangeLabel(DateRange $range): string
    {
        return sprintf('%s to %s', $range->from->format('Y-m-d'), $range->until->format('Y-m-d'));
    }

    /**
     * Every metric the page module shows a tile for, in the order the
     * providers declare. A metric that stayed at nothing across both periods
     * keeps its row here, unlike the marker block in DayDetailBuilder: that
     * one answers for a single event and a row saying nothing buries the one
     * that does, while this view is asked to account for the whole period
     * and a metric that never moved is part of the answer.
     *
     * @param list<int> $pageUids
     *
     * @return list<PeriodComparisonRow>
     */
    private function rows(Site $site, array $pageUids, int $languageId, DateRange $current, DateRange $reference): array
    {
        /** @var list<array{sortOrder: int, row: PeriodComparisonRow}> $ordered */
        $ordered = [];

        foreach ($this->metricDefinitionCollector->supporting($this->providers, $site) as $provider) {
            $coverage = $this->importCoverage->forProvider($provider->getIdentifier(), $site->getIdentifier());
            // Both periods, not only the reference one. A selected period
            // reaching back before the import started sums days nobody
            // fetched, and holding that against a fully imported baseline
            // reports a collapse that only ever happened to the history.
            $isComparable = $this->importCoverage->covers($coverage, $current) && $this->importCoverage->covers($coverage, $reference);

            foreach ($this->metricDefinitionCollector->forSite($provider, $site) as $definition) {
                // Machine traffic is one folded summary tile on every other
                // surface, never a row per bucket, and folding it again here
                // would be a second copy of that rule. What this view
                // answers is what people did.
                if (MetricGroup::Machine === $definition->group) {
                    continue;
                }

                $ordered[] = [
                    'sortOrder' => $definition->sortOrder,
                    'row' => $this->countRow($definition, $pageUids, $languageId, $current, $reference, $isComparable),
                ];
            }

            foreach ($provider->getRateMetricDefinitions() as $definition) {
                $ordered[] = [
                    'sortOrder' => $definition->sortOrder,
                    'row' => $this->rateRow($definition, $pageUids, $languageId, $current, $reference, $isComparable),
                ];
            }
        }

        usort($ordered, static fn (array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder']);

        return array_map(static fn (array $entry): PeriodComparisonRow => $entry['row'], $ordered);
    }

    /**
     * @param list<int> $pageUids
     */
    private function countRow(MetricDefinition $definition, array $pageUids, int $languageId, DateRange $current, DateRange $reference, bool $isComparable): PeriodComparisonRow
    {
        $value = $this->sum($pageUids, $languageId, $definition->identifier, $current);
        // Not even asked for outside the imported window, exactly as
        // PageMetricsViewDataBuilder::previousSum() refuses to ask: the sum
        // would be built from fabricated zeros and nothing downstream may
        // use it.
        $referenceValue = $isComparable ? $this->sum($pageUids, $languageId, $definition->identifier, $reference) : 0;

        return $this->row($definition, $value, $referenceValue, $isComparable);
    }

    /**
     * A rate is summed as numerator over denominator across the whole
     * period rather than averaged per day, the one arithmetic
     * RateMetricReader exists to keep identical everywhere.
     *
     * The reference period is left unasked outside the imported window for
     * the same reason countRow() leaves it unasked.
     *
     * @param list<int> $pageUids
     */
    private function rateRow(RateMetricDefinition $definition, array $pageUids, int $languageId, DateRange $current, DateRange $reference, bool $isComparable): PeriodComparisonRow
    {
        $value = $this->rateMetricReader->forSite($pageUids, $languageId, $definition, $current)->ratio;
        $referenceValue = $isComparable ? $this->rateMetricReader->forSite($pageUids, $languageId, $definition, $reference)->ratio : 0;

        return $this->row($definition, $value, $referenceValue, $isComparable);
    }

    private function row(MetricDefinition|RateMetricDefinition $definition, int $value, int $referenceValue, bool $isComparable): PeriodComparisonRow
    {
        $trend = $isComparable ? MetricTrend::between($value, $referenceValue) : MetricTrend::none();
        $significance = $definition->significance ?? MetricSignificance::default();

        return new PeriodComparisonRow(
            $definition->identifier,
            $definition->label,
            $definition->description,
            $definition->icon,
            $definition->color,
            $this->valueFormatter->format($value, $definition->unit),
            $isComparable ? $this->valueFormatter->format($referenceValue, $definition->unit) : '',
            $isComparable ? $this->formatDifference($value - $referenceValue, $definition->unit) : '',
            $trend->deltaPercent,
            $trend->direction,
            TrendSentiment::of($trend->direction, $definition->polarity),
            $isComparable && $significance->clears($value, $referenceValue),
            $isComparable,
        );
    }

    /**
     * The sign is prefixed rather than left to the formatter, which spells a
     * duration as minutes and seconds: a drop of seventy seconds through it
     * reads "-1:-10", and a negative amount of currency loses its sign to
     * the minor-unit division entirely.
     */
    private function formatDifference(int $difference, MetricUnit $unit): string
    {
        $sign = match (true) {
            $difference > 0 => '+',
            $difference < 0 => '-',
            default => '',
        };

        return $sign.$this->valueFormatter->format(abs($difference), $unit);
    }

    /**
     * @param list<int> $pageUids
     */
    private function sum(array $pageUids, int $languageId, string $metric, DateRange $range): int
    {
        return array_sum($this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $metric, $range));
    }

    private function resolveSite(int $pageId): ?Site
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageId);
        } catch (Throwable) {
            return null;
        }
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
