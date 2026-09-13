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
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

use function abs;
use function array_map;
use function array_sum;
use function preg_match;
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
    private const LL = 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:';

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
    public function build(int $pageId, int $languageId, int $periodDays, ?string $baselineKey, bool $includeSubpages = false, bool $withChart = true): ?PeriodComparison
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

        // No chart where the two periods share days. Only the start moves
        // back, so a 90 day period against "one month earlier" overlaps by
        // 59 of them: the table has always compared those totals, but drawing
        // them as two lines shows a period held against itself, and the prose
        // beside the chart says in as many words that the periods cover
        // different days. A drawing that contradicts its own caption is worse
        // than no drawing.
        //
        // $withChart is false for the CSV export, which reads neither the
        // series nor the label: chart() costs two full-range queries, and the
        // pages export pays for none of its own drawing either.
        $chartMetric = $withChart && !$current->overlaps($reference) ? $this->chartMetric($site) : null;

        return new PeriodComparison(
            $baseline,
            $periodDays,
            self::rangeLabel($current),
            self::rangeLabel($reference),
            $this->rows($site, $pageUids, $languageId, $current, $reference),
            ComparisonBaseline::all(),
            null === $chartMetric ? [] : $this->chart($chartMetric['provider'], $chartMetric['definition'], $site, $pageUids, $languageId, $current, $reference),
            null === $chartMetric ? '' : $chartMetric['definition']->label,
            $current->overlaps($reference),
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
     * The one metric the chart carries, out of the several the table does.
     *
     * The site's primary counted metric, which is the first one its
     * providers declare. Machine traffic is left out here exactly as it is
     * left out of the rows.
     *
     * Close to, but not the same as, the rule the page view's chart picks by:
     * that one runs over built tiles and skips a metric with no data for the
     * page, this one runs over definitions and does not. On a page whose
     * leading metric happens to be empty the two views of this module chart
     * different things. Which of the three rules in the codebase is the right
     * one is #88; naming the divergence here rather than asserting a
     * consistency that does not hold.
     *
     * Which metric is drawn is not a choice the reader can make yet, and
     * cannot be made one from here: a picker is a set of links like the
     * baseline picker (a GET form drops the request token), and the key
     * those links carry has to be declared in the module's `moduleData` and
     * read by the controller before this class ever sees it.
     *
     * @return array{provider: string, definition: MetricDefinition}|null
     */
    private function chartMetric(Site $site): ?array
    {
        /** @var list<array{sortOrder: int, provider: string, definition: MetricDefinition}> $candidates */
        $candidates = [];

        foreach ($this->metricDefinitionCollector->supporting($this->providers, $site) as $provider) {
            foreach ($this->metricDefinitionCollector->forSite($provider, $site) as $definition) {
                if (MetricUnit::Count !== $definition->unit || MetricGroup::Machine === $definition->group) {
                    continue;
                }

                $candidates[] = ['sortOrder' => $definition->sortOrder, 'provider' => $provider->getIdentifier(), 'definition' => $definition];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder']);
        $first = $candidates[0] ?? null;

        return null === $first ? null : ['provider' => $first['provider'], 'definition' => $first['definition']];
    }

    /**
     * The selected period first and the baseline second, which is the order
     * the renderer draws and legends them in.
     *
     * @param list<int> $pageUids
     *
     * @return list<array{label: string, color: string, unit: string, subordinate: bool, values: array<string, int|null>}>
     */
    private function chart(string $provider, MetricDefinition $definition, Site $site, array $pageUids, int $languageId, DateRange $current, DateRange $reference): array
    {
        $coverage = $this->importCoverage->forProvider($provider, $site->getIdentifier());
        $dayLabel = $this->dayLabel();

        return [
            $this->chartSeries($this->translate('module.compare.chart.selected', 'Selected period'), $definition, $pageUids, $languageId, $current, $coverage, $dayLabel, false),
            // Its own key rather than the "Compared against" of the table
            // header: a legend entry has to name the line, and the prose
            // beside the chart calls it the earlier period.
            $this->chartSeries($this->translate('module.compare.chart.baseline', 'Earlier period'), $definition, $pageUids, $languageId, $reference, $coverage, $dayLabel, true),
        ];
    }

    /**
     * Both series carry the metric's own colour rather than one apiece: they
     * are the same metric, and a second hue would say they are not. What
     * separates them is the dash the renderer gives the subordinate one,
     * which is geometry and therefore survives a forced-colours mode, and
     * the range each legend entry names, which survives having no sight of
     * the chart at all.
     *
     * @param list<int> $pageUids
     *
     * @return array{label: string, color: string, unit: string, subordinate: bool, values: array<string, int|null>}
     */
    private function chartSeries(string $name, MetricDefinition $definition, array $pageUids, int $languageId, DateRange $range, ?DateRange $coverage, string $dayLabel, bool $subordinate): array
    {
        // Masked exactly as the tiles mask their sparkline: a day outside the
        // imported window is blank, never a point at zero, which is the whole
        // difference between "nothing happened" and "nobody asked".
        $series = $this->importCoverage->mask(
            $this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $definition->identifier, $range),
            $coverage,
        );

        $values = [];
        $day = 1;
        foreach ($series as $value) {
            $values[sprintf($dayLabel, $day)] = $value;
            ++$day;
        }

        return [
            'label' => sprintf('%s, %s', $name, self::rangeLabel($range)),
            'color' => $definition->color,
            'unit' => $definition->unit->value,
            'subordinate' => $subordinate,
            'values' => $values,
        ];
    }

    /**
     * The x axis of a two-period chart, and the only honest one: the reader
     * is looking at two different date ranges, so a date would be right for
     * one line and wrong for the other.
     */
    private function dayLabel(): string
    {
        $pattern = $this->translate('module.compare.chart.day', 'Day %d');

        // Exactly one specifier, not merely a present one. A translation that
        // lost the placeholder would fold every day onto one key and leave a
        // chart of a single point; one that gained a second ("Tag %d von %s")
        // or carries a stray literal % reaches sprintf() with one argument
        // and throws, taking the whole view and the compare export with it.
        // A pattern nobody can format is refused rather than trusted.
        return 1 === preg_match('/^[^%]*%d[^%]*$/', $pattern) ? $pattern : 'Day %d';
    }

    /**
     * sL() answers with an empty string for a key it cannot resolve, and an
     * empty legend entry is a line nobody can name.
     */
    private function translate(string $key, string $fallback): string
    {
        $label = $this->getLanguageService()->sL(self::LL.$key);

        return '' === $label ? $fallback : $label;
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

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
