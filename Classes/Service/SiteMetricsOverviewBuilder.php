<?php

declare(strict_types=1);

/*
 * This file is part of the "page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Service;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricUnit, TrendDirection, UnresolvedReason};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, RateMetricDefinition, SiteMetricsOverview, TopPage, UnresolvedUrlSummary};
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, SitePageUidRepository, UnresolvedUrlRepository};
use KonradMichalik\Typo3PageMetrics\Source\MetricSourceInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

use function array_slice;
use function sprintf;
use function ucfirst;

/**
 * SiteMetricsOverviewBuilder.
 *
 * The site-wide counterpart to PageMetricsViewDataBuilder - backs the four
 * dashboard widgets (chart, top pages, not-found pages, annotations) the
 * same way that class backs the PageHeader tiles. Ranks "most viewed pages"
 * by whichever metric a supporting source declares first (lowest
 * sortOrder), the source's own "pageviews"-equivalent by convention - the
 * chart can be pointed at a different metric via $preferredMetricIdentifier.
 * The "biggest trend" widget goes through buildTrendPages() instead, since
 * it has its own period and ranking axis independent of the others.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SiteMetricsOverviewBuilder
{
    private const TOP_PAGES_LIMIT = 5;
    private const UNRESOLVED_LIMIT = 5;

    /**
     * @param iterable<MetricSourceInterface> $sources
     */
    public function __construct(
        private readonly SitePageUidRepository $pageUidRepository,
        private readonly DailyMetricsRepository $dailyMetricsRepository,
        private readonly UnresolvedUrlRepository $unresolvedUrlRepository,
        private readonly AnnotationRepository $annotationRepository,
        private readonly iterable $sources,
    ) {}

    public function build(Site $site, int $languageId, int $periodDays, ?string $preferredMetricIdentifier = null): SiteMetricsOverview
    {
        $source = $this->resolveSource($site);

        if (null === $source) {
            return new SiteMetricsOverview($site, false, '', '', '', MetricUnit::Count, [], [], [], []);
        }

        $primary = $this->primaryDefinition($source);
        $chartMetric = $this->resolveChartMetric($source, $preferredMetricIdentifier) ?? $primary;

        [$currentRange, $previousRange] = $this->computeRanges($periodDays);
        $pageUids = $this->pageUidRepository->descendantsOf($site->getRootPageId());

        return new SiteMetricsOverview(
            $site,
            true,
            $chartMetric->label,
            $chartMetric->icon,
            $chartMetric->color,
            $chartMetric->unit,
            $this->buildChartSeries($chartMetric, $pageUids, $languageId, $currentRange),
            $this->buildTopPages($pageUids, $languageId, $primary, $currentRange, $previousRange),
            $this->buildUnresolvedUrls($site),
            $this->annotationRepository->forSiteAndRange($site->getIdentifier(), $currentRange),
        );
    }

    /**
     * The highest (direction: Up) or lowest (direction: Down) trending
     * pages by the site's primary metric, over their own period - used by
     * the standalone "trend" widget, which is user-configurable for both
     * independent of the other widgets' fixed periods.
     *
     * @return ?list<TopPage> null when no source supports $site
     */
    public function buildTrendPages(Site $site, int $languageId, int $periodDays, TrendDirection $direction, int $limit = self::TOP_PAGES_LIMIT): ?array
    {
        $source = $this->resolveSource($site);

        if (null === $source) {
            return null;
        }

        $primary = $this->primaryDefinition($source);
        [$currentRange, $previousRange] = $this->computeRanges($periodDays);
        $pageUids = $this->pageUidRepository->descendantsOf($site->getRootPageId());

        $trends = $this->computePageTrends($pageUids, $languageId, $primary->identifier, $currentRange, $previousRange);
        $trends = array_filter($trends, static fn (array $trend): bool => null !== $trend['deltaPercent'] && $trend['trend'] === $direction);

        usort(
            $trends,
            TrendDirection::Down === $direction
                ? static fn (array $a, array $b): int => $a['deltaPercent'] <=> $b['deltaPercent']
                : static fn (array $a, array $b): int => $b['deltaPercent'] <=> $a['deltaPercent'],
        );

        return $this->toTopPages(array_slice($trends, 0, $limit), $primary->unit);
    }

    /**
     * Which source ends up serving a given site is already resolved
     * automatically from site configuration (see resolveSource()) - callers
     * pass the sites the resulting list needs to cover ($sites), so an
     * installation only ever using one source never has to show it, even
     * though both Matomo and GA4 support classes are always registered
     * (see Configuration/Services.yaml) regardless of which sites actually
     * use them. Within whatever sources are relevant, labels are only
     * prefixed with the owning source's display name for the identifiers
     * that actually need it: different sources reuse the same label text
     * for several metrics (Matomo and GA4 both call metrics "Page views",
     * "Avg. time on page" and "Bounce rate"), which would otherwise leave
     * identical-looking, differently-behaving entries in the dropdown once
     * a site mix pulls in both. A label unique among the relevant sources
     * (e.g. Matomo's "Visits" vs. GA4's "Sessions") is shown as-is.
     *
     * @param iterable<Site> $sites restricts the result to sources supporting at least one of these sites
     *
     * @return array<string, string> metric identifier => label, across every relevant source
     */
    public function availableMetrics(iterable $sites): array
    {
        $sources = $this->relevantSources($sites);
        $definitions = [];

        foreach ($sources as $source) {
            foreach ([...$source->getMetricDefinitions(), ...$source->getRateMetricDefinitions()] as $definition) {
                $definitions[$definition->identifier] = ['source' => $source, 'label' => $definition->label];
            }
        }

        $labelCounts = array_count_values(array_column($definitions, 'label'));

        $metrics = [];

        foreach ($definitions as $identifier => $definition) {
            $metrics[$identifier] = $labelCounts[$definition['label']] > 1
                ? sprintf('%s: %s', $this->sourceDisplayName($definition['source']), $definition['label'])
                : $definition['label'];
        }

        return $metrics;
    }

    /**
     * @param iterable<Site> $sites
     *
     * @return list<MetricSourceInterface>
     */
    private function relevantSources(iterable $sites): array
    {
        $sources = [];

        foreach ($this->sources as $source) {
            foreach ($sites as $site) {
                if ($source->supports($site)) {
                    $sources[] = $source;

                    continue 2;
                }
            }
        }

        return $sources;
    }

    /**
     * MetricSourceInterface has no dedicated display-name method - adding
     * one would be a breaking change for third-party sources (the interface
     * is @api, see AnnotationSourceInterface's sibling comment) for the sake
     * of cosmetics only relevant when several sources are mixed. First-party
     * sources get a proper name here; anything else falls back to a
     * capitalized identifier rather than failing.
     */
    private function sourceDisplayName(MetricSourceInterface $source): string
    {
        return match ($source->getIdentifier()) {
            'matomo' => 'Matomo',
            'ga4' => 'GA4',
            default => ucfirst($source->getIdentifier()),
        };
    }

    private function resolveSource(Site $site): ?MetricSourceInterface
    {
        foreach ($this->sources as $source) {
            if ($source->supports($site)) {
                return $source;
            }
        }

        return null;
    }

    private function primaryDefinition(MetricSourceInterface $source): MetricDefinition
    {
        $definitions = $source->getMetricDefinitions();
        usort($definitions, static fn (MetricDefinition $a, MetricDefinition $b): int => $a->sortOrder <=> $b->sortOrder);

        return $definitions[0];
    }

    /**
     * Resolves $preferredMetricIdentifier against $source's own metrics
     * (count and rate alike) - null when no identifier was requested, or
     * the requested one does not belong to this site's own source (e.g. a
     * chart widget configured for a Matomo metric on a GA4-only site),
     * both cases falling back to the primary metric in build().
     */
    private function resolveChartMetric(MetricSourceInterface $source, ?string $preferredMetricIdentifier): MetricDefinition|RateMetricDefinition|null
    {
        if (null === $preferredMetricIdentifier || '' === $preferredMetricIdentifier) {
            return null;
        }

        foreach ($source->getMetricDefinitions() as $definition) {
            if ($definition->identifier === $preferredMetricIdentifier) {
                return $definition;
            }
        }

        foreach ($source->getRateMetricDefinitions() as $definition) {
            if ($definition->identifier === $preferredMetricIdentifier) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @param list<int> $pageUids
     *
     * @return array<string, int> Y-m-d => value
     */
    private function buildChartSeries(MetricDefinition|RateMetricDefinition $metric, array $pageUids, int $languageId, DateRange $range): array
    {
        if ($metric instanceof MetricDefinition) {
            return $this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $metric->identifier, $range);
        }

        $numerator = $this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $metric->numerator, $range);
        $denominator = $this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $metric->denominator, $range);

        $series = [];
        foreach ($numerator as $date => $numeratorValue) {
            $series[$date] = $this->computeRatio($numeratorValue, $denominator[$date] ?? 0, $metric->unit);
        }

        return $series;
    }

    /**
     * @return array{0: DateRange, 1: DateRange}
     */
    private function computeRanges(int $periodDays): array
    {
        $today = new DateTimeImmutable('today');
        $currentRange = new DateRange($today->modify(sprintf('-%d days', $periodDays - 1)), $today);
        $previousUntil = $currentRange->from->modify('-1 day');
        $previousRange = new DateRange($previousUntil->modify(sprintf('-%d days', $periodDays - 1)), $previousUntil);

        return [$currentRange, $previousRange];
    }

    /**
     * @param list<int> $pageUids
     *
     * @return list<TopPage>
     */
    private function buildTopPages(array $pageUids, int $languageId, MetricDefinition $primary, DateRange $currentRange, DateRange $previousRange): array
    {
        $trends = $this->computePageTrends($pageUids, $languageId, $primary->identifier, $currentRange, $previousRange);
        usort($trends, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return $this->toTopPages(array_slice($trends, 0, self::TOP_PAGES_LIMIT), $primary->unit);
    }

    /**
     * Every page with data in $currentRange and/or $previousRange, with its
     * trend against the previous period - the shared data source for
     * "most viewed pages" (sorted by value) and the "trend" widget (sorted
     * by deltaPercent). A page with zero current traffic but real previous
     * traffic still appears here (a real, meaningful drop), which is why
     * this does not simply reuse DailyMetricsRepository::topPages().
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

    /**
     * @return list<UnresolvedUrlSummary>
     */
    private function buildUnresolvedUrls(Site $site): array
    {
        $since = (new DateTimeImmutable('today'))->modify('-30 days');
        $rows = $this->unresolvedUrlRepository->listForSite($site->getBase()->getHost(), $since, self::UNRESOLVED_LIMIT);

        return array_map(
            static fn (array $row): UnresolvedUrlSummary => new UnresolvedUrlSummary(
                $row['url'],
                UnresolvedReason::tryFrom($row['reason']) ?? UnresolvedReason::Unknown,
                $row['value'],
            ),
            $rows,
        );
    }
}
