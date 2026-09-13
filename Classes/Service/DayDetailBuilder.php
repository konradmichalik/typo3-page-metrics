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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricGroup, TrendSentiment};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, BeforeAfterComparison, ComparisonWindow, DateRange, DayComparison, DayDetail, DayMetricValue, MetricDefinition, MetricTrend, RateMetricDefinition};
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, ManualAnnotationRepository, SitePageUidRepository};
use KonradMichalik\Typo3PageMetrics\Provider\{MetricDefinitionCollector, MetricProviderInterface};
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

use function array_map;
use function array_sum;
use function sprintf;
use function usort;

/**
 * DayDetailBuilder.
 *
 * What one day looked like on one page: the markers that explain it, what
 * each metric did on it, and - for a marker that belongs to this page - the
 * days before it against the days after it.
 *
 * A sibling of PageMetricsViewDataBuilder rather than a method on it: that
 * one answers "how is this page doing over a period", this one answers "what
 * happened here on this day", and the two share their repositories, not
 * their shape. Both are reached only through an AJAX route, because both do
 * a query per metric and neither belongs anywhere near the page module's own
 * synchronous render.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class DayDetailBuilder
{
    /**
     * Two weeks either side. Long enough that a weekday/weekend rhythm
     * cancels out on both sides, short enough that the next release is
     * usually not already inside the window it would otherwise be credited
     * to. It is also the figure the spec uses to argue for the whole marker
     * package, so it stays a constant until somebody has a second defensible
     * number rather than becoming one more thing to configure.
     */
    private const COMPARISON_WINDOW_DAYS = 14;

    /**
     * @param iterable<MetricProviderInterface> $providers
     */
    public function __construct(
        private SiteFinder $siteFinder,
        private DailyMetricsRepository $dailyMetricsRepository,
        private AnnotationRepository $annotationRepository,
        private ManualAnnotationRepository $manualAnnotationRepository,
        private SitePageUidRepository $pageUidRepository,
        private RateSeriesCalculator $rateSeriesCalculator,
        private ImportCoverage $importCoverage,
        private MetricValueFormatter $valueFormatter,
        private MetricDefinitionCollector $metricDefinitionCollector,
        private iterable $providers,
    ) {}

    /**
     * Null for anything this backend user may not see, which is the same
     * decision PageMetricsViewDataBuilder makes and for the same reason: the
     * page uid arrives from the request, so the permission check is what
     * stands between a hand-built URL and another page's numbers.
     */
    public function build(int $pageId, DateTimeImmutable $date, bool $includeSubpages = false): ?DayDetail
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

        $day = $date->setTime(0, 0);
        $pageUids = $includeSubpages ? $this->pageUidRepository->descendantsOf($pageId) : [$pageId];
        $annotations = $this->annotationsOn($site->getIdentifier(), $day, $pageId);

        return new DayDetail(
            $day,
            $annotations,
            $this->metricValuesOn($site, $pageUids, $day),
            $this->comparisonsFor($site, $pageUids, $annotations),
        );
    }

    /**
     * Every marker covering this one day, from both tables, scoped to this
     * page exactly as the tiles scope theirs. A range marker answers for
     * every day it spans, not only the one it began on, which the shared
     * range query already handles.
     *
     * @return list<Annotation>
     */
    private function annotationsOn(string $siteIdentifier, DateTimeImmutable $day, int $pageUid): array
    {
        $range = new DateRange($day, $day);
        $ancestors = $this->pageUidRepository->ancestorsOf($pageUid);

        $annotations = [
            ...$this->annotationRepository->forSiteAndRange($siteIdentifier, $range, $pageUid, $ancestors),
            ...$this->manualAnnotationRepository->forSiteAndRange($siteIdentifier, $range, $pageUid, $ancestors),
        ];

        usort($annotations, static fn (Annotation $a, Annotation $b): int => $a->date <=> $b->date);

        return $annotations;
    }

    /**
     * @param list<int> $pageUids
     *
     * @return list<DayMetricValue>
     */
    private function metricValuesOn(Site $site, array $pageUids, DateTimeImmutable $day): array
    {
        $range = new DateRange($day, $day);
        /** @var list<array{sortOrder: int, value: DayMetricValue}> $ordered */
        $ordered = [];

        foreach ($this->providers as $provider) {
            if (!$provider->supports($site)) {
                continue;
            }

            $coverage = $this->importCoverage->forProvider($provider->getIdentifier(), $site->getIdentifier());

            foreach ($this->metricDefinitionCollector->forSite($provider, $site) as $definition) {
                // Machine traffic is one folded summary tile, never a row per
                // bucket, and folding it again here would be a second copy of
                // that rule. What this panel answers is what people did that
                // day.
                if (MetricGroup::Machine === $definition->group) {
                    continue;
                }

                $ordered[] = [
                    'sortOrder' => $definition->sortOrder,
                    'value' => $this->countMetricValue($definition, $pageUids, $range, $coverage),
                ];
            }

            foreach ($provider->getRateMetricDefinitions() as $definition) {
                $ordered[] = [
                    'sortOrder' => $definition->sortOrder,
                    'value' => $this->rateMetricValue($definition, $pageUids, $range, $coverage),
                ];
            }
        }

        usort($ordered, static fn (array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder']);

        return array_map(static fn (array $entry): DayMetricValue => $entry['value'], $ordered);
    }

    /**
     * @param list<int> $pageUids
     */
    private function countMetricValue(MetricDefinition $definition, array $pageUids, DateRange $range, ?DateRange $coverage): DayMetricValue
    {
        $value = $this->dayValue($pageUids, $definition->identifier, $range, $coverage);

        return new DayMetricValue(
            $definition->identifier,
            $definition->label,
            $definition->icon,
            $definition->color,
            null === $value ? '' : $this->valueFormatter->format($value, $definition->unit),
            null !== $value,
        );
    }

    /**
     * @param list<int> $pageUids
     */
    private function rateMetricValue(RateMetricDefinition $definition, array $pageUids, DateRange $range, ?DateRange $coverage): DayMetricValue
    {
        $numerator = $this->dayValue($pageUids, $definition->numerator, $range, $coverage);
        $denominator = $this->dayValue($pageUids, $definition->denominator, $range, $coverage);

        // A rate with nothing underneath it is not zero percent, it is a
        // question the day cannot answer: nobody arrived, so nobody bounced
        // either.
        $value = null === $numerator || null === $denominator || 0 === $denominator
            ? null
            : $this->rateSeriesCalculator->ratio($numerator, $denominator, $definition->unit);

        return new DayMetricValue(
            $definition->identifier,
            $definition->label,
            $definition->icon,
            $definition->color,
            null === $value ? '' : $this->valueFormatter->format($value, $definition->unit),
            null !== $value,
        );
    }

    /**
     * @param list<int>        $pageUids
     * @param list<Annotation> $annotations
     *
     * @return list<DayComparison>
     */
    private function comparisonsFor(Site $site, array $pageUids, array $annotations): array
    {
        $comparisons = [];

        foreach ($annotations as $annotation) {
            if (0 === $annotation->pageUid) {
                continue;
            }

            $window = ComparisonWindow::around($annotation->date, $annotation->dateUntil, self::COMPARISON_WINDOW_DAYS);
            $rows = $this->comparisonRows($site, $pageUids, $window);

            if ([] === $rows) {
                continue;
            }

            $comparisons[] = new DayComparison(
                $annotation->text,
                $annotation->getCategoryIcon(),
                self::COMPARISON_WINDOW_DAYS,
                $this->rangeLabel($window->before),
                $this->rangeLabel($window->after),
                $rows,
            );
        }

        return $comparisons;
    }

    /**
     * @param list<int> $pageUids
     *
     * @return list<BeforeAfterComparison>
     */
    private function comparisonRows(Site $site, array $pageUids, ComparisonWindow $window): array
    {
        // The last day anything can be known about. Today is still being
        // lived through and the import only marks a day once it has elapsed,
        // so counting it as a day of the window would report every marker
        // from two weeks ago as one day short forever.
        $latestKnownDay = (new DateTimeImmutable('today'))->modify('-1 day');
        $rows = [];

        foreach ($this->providers as $provider) {
            if (!$provider->supports($site)) {
                continue;
            }

            $coverage = $this->importCoverage->forProvider($provider->getIdentifier(), $site->getIdentifier());

            // Counts only, and no rate metrics at all. The claim this block
            // exists to make is "this many more people", and a rate has
            // already divided exactly that away: a bounce rate holding steady
            // while traffic doubled is a success the row would print as no
            // change. Machine traffic is out for the same reason it is out of
            // the day's figures above.
            foreach ($this->metricDefinitionCollector->forSite($provider, $site) as $definition) {
                if (MetricGroup::Machine === $definition->group) {
                    continue;
                }

                $row = $this->comparisonRow($definition, $pageUids, $window, $coverage, $latestKnownDay);

                if (null !== $row) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @param list<int> $pageUids
     */
    private function comparisonRow(MetricDefinition $definition, array $pageUids, ComparisonWindow $window, ?DateRange $coverage, DateTimeImmutable $latestKnownDay): ?BeforeAfterComparison
    {
        $before = array_sum($this->dailyMetricsRepository->siteSeries($pageUids, 0, $definition->identifier, $window->before));
        $after = array_sum($this->dailyMetricsRepository->siteSeries($pageUids, 0, $definition->identifier, $window->after));

        // Nothing on either side of the marker is not a comparison, it is a
        // metric this page has never recorded. Printed as "0 against 0, new"
        // it would fill the block with rows that say nothing and bury the one
        // that does.
        if (0 === $before && 0 === $after) {
            return null;
        }

        $trend = MetricTrend::between($after, $before);

        return new BeforeAfterComparison(
            $definition->label,
            $this->valueFormatter->format($before, $definition->unit),
            $this->valueFormatter->format($after, $definition->unit),
            $trend->deltaPercent,
            $trend->direction,
            TrendSentiment::of($trend->direction, $definition->polarity),
            $window->isComplete($coverage, $latestKnownDay),
            $window->availableBeforeDays($coverage, $latestKnownDay),
            $window->availableAfterDays($coverage, $latestKnownDay),
        );
    }

    /**
     * @param list<int> $pageUids
     */
    private function dayValue(array $pageUids, string $metric, DateRange $range, ?DateRange $coverage): ?int
    {
        $masked = $this->importCoverage->mask(
            $this->dailyMetricsRepository->siteSeries($pageUids, 0, $metric, $range),
            $coverage,
        );

        return array_values($masked)[0] ?? null;
    }

    private function rangeLabel(DateRange $range): string
    {
        return sprintf('%s to %s', $range->from->format('Y-m-d'), $range->until->format('Y-m-d'));
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
