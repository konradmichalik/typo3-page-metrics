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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{AnnotationCategory, MetricUnit, TrendSentiment, UnresolvedReason};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, ChartSeries, DataFreshness, DateRange, MetricDefinition, RateMetricDefinition, SiteMetricsOverview, TopPage, UnresolvedUrlSummary};
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, ImportStateRepository, ManualAnnotationRepository, SitePageUidRepository, UnresolvedUrlRepository};
use KonradMichalik\Typo3PageMetrics\Provider\{MetricDefinitionCollector, MetricProviderInterface};
use TYPO3\CMS\Core\Site\Entity\Site;

use function in_array;
use function sprintf;
use function ucfirst;

/**
 * SiteMetricsOverviewBuilder.
 *
 * The site-wide counterpart to PageMetricsViewDataBuilder - backs the four
 * dashboard widgets (chart, top pages, not-found pages, annotations) the
 * same way that class backs the PageHeader tiles. Ranks "most viewed pages"
 * by whichever metric a supporting provider declares first (lowest
 * sortOrder), the provider's own "pageviews"-equivalent by convention - the
 * chart can be pointed at a different metric via $preferredMetricIdentifier.
 * The "biggest trend" widget goes through buildTrendPages() instead, since
 * it has its own period and ranking axis independent of the others. Per-page
 * trend/ranking math itself lives in PageTrendCalculator, shared by both.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SiteMetricsOverviewBuilder
{
    private const TOP_PAGES_LIMIT = 5;
    private const UNRESOLVED_LIMIT = 5;

    /**
     * @param iterable<MetricProviderInterface> $providers
     */
    public function __construct(
        private SitePageUidRepository $pageUidRepository,
        private DailyMetricsRepository $dailyMetricsRepository,
        private UnresolvedUrlRepository $unresolvedUrlRepository,
        private AnnotationRepository $annotationRepository,
        private ManualAnnotationRepository $manualAnnotationRepository,
        private PageTrendCalculator $trendCalculator,
        private RateSeriesCalculator $rateSeriesCalculator,
        private ImportCoverage $importCoverage,
        private ImportStateRepository $importStateRepository,
        private MetricDefinitionCollector $metricDefinitionCollector,
        private iterable $providers,
    ) {}

    /**
     * @param list<string> $additionalMetricIdentifiers overlaid on the chart alongside $preferredMetricIdentifier (see PageMetricsChartWidget's metric2 setting) - an identifier not belonging to this site's own provider is silently dropped, the same fallback buildAdditionalChartSeries() already applies to $preferredMetricIdentifier
     */
    public function build(Site $site, int $languageId, int $periodDays, ?string $preferredMetricIdentifier = null, array $additionalMetricIdentifiers = [], ?AnnotationCategory $annotationCategory = null): SiteMetricsOverview
    {
        $provider = $this->resolveProvider($site);

        if (null === $provider) {
            return new SiteMetricsOverview($site, false, '', '', '', MetricUnit::Count, [], [], [], [], [], null);
        }

        $primary = $this->primaryDefinition($provider, $site);
        $chartMetric = $this->resolveChartMetric($provider, $site, $preferredMetricIdentifier) ?? $primary;

        [$currentRange, $previousRange] = $this->computeRanges($periodDays);
        $pageUids = $this->pageUidRepository->descendantsOf($site->getRootPageId());
        $coverage = $this->importCoverage->forProvider($provider->getIdentifier(), $site->getIdentifier());

        return new SiteMetricsOverview(
            $site,
            true,
            $chartMetric->label,
            $chartMetric->icon,
            $chartMetric->color,
            $chartMetric->unit,
            $this->buildChartSeries($chartMetric, $pageUids, $languageId, $currentRange, $coverage),
            $this->trendCalculator->rankedByValue($pageUids, $languageId, $primary, $currentRange, $previousRange, self::TOP_PAGES_LIMIT, $this->importCoverage->covers($coverage, $previousRange)),
            $this->buildUnresolvedUrls($site),
            $this->buildAnnotations($site->getIdentifier(), $currentRange, $annotationCategory),
            $this->buildAdditionalChartSeries($provider, $site, $additionalMetricIdentifiers, $pageUids, $languageId, $currentRange, $coverage),
            $this->dataFreshnessFor($site),
        );
    }

    /**
     * The last day this site's numbers are complete up to. Null when no
     * provider supports the site, or when the one that does has never run.
     *
     * Read from the resume cursor rather than the covered window, for the
     * same reason as PageMetricsViewDataBuilder::resolveDataFreshness():
     * every run records the cursor, while the window can legitimately be
     * unknown, and "no date" is the wrong thing to say about a scheduler
     * that may have stopped.
     *
     * Takes no period: every period this extension offers ends today, so
     * there is nothing for one to change here.
     */
    public function dataFreshnessFor(Site $site): ?DataFreshness
    {
        $provider = $this->resolveProvider($site);

        if (null === $provider) {
            return null;
        }

        $lastImported = $this->importStateRepository->getLastImportedDate($provider->getIdentifier(), $site->getIdentifier());

        return null !== $lastImported ? new DataFreshness($lastImported, new DateTimeImmutable('today')) : null;
    }

    /**
     * The highest (direction: Up) or lowest (direction: Down) trending
     * pages by $preferredMetricIdentifier (the site's primary metric when
     * null, or when the requested identifier does not belong to this
     * site's own provider), over their own period - used by the standalone
     * "trend" widget, which is user-configurable for all three independent
     * of the other widgets' fixed periods.
     *
     * @return ?list<TopPage> null when no provider supports $site
     */
    public function buildTrendPages(Site $site, int $languageId, int $periodDays, TrendSentiment $sentiment, ?string $preferredMetricIdentifier = null, int $limit = self::TOP_PAGES_LIMIT): ?array
    {
        $provider = $this->resolveProvider($site);

        if (null === $provider) {
            return null;
        }

        $primary = $this->primaryDefinition($provider, $site);
        $metric = $this->resolveChartMetric($provider, $site, $preferredMetricIdentifier) ?? $primary;
        [$currentRange, $previousRange] = $this->computeRanges($periodDays);
        $pageUids = $this->pageUidRepository->descendantsOf($site->getRootPageId());

        return $this->trendCalculator->rankedByTrend($pageUids, $languageId, $metric, $currentRange, $previousRange, $sentiment, $limit, $this->previousPeriodCovered($provider, $site, $previousRange));
    }

    /**
     * The highest-traffic pages for one site, restricted to pages at an
     * exact depth below the site root (level 1 = direct children) instead
     * of the whole subtree when $level is given - used by the standalone
     * "most viewed pages" widget, which is user-configurable for this
     * independent of the combined build() used by the other site-metrics
     * widgets. $includeSubpages only has an effect together with $level:
     * each page at that level then represents its own value plus every one
     * of its own subpages' - without a level, every individual page is
     * already its own row, so there is nothing to roll up into.
     *
     * @return ?list<TopPage> null when no provider supports $site
     */
    public function buildTopPagesForSite(Site $site, int $languageId, int $periodDays, ?int $level = null, bool $includeSubpages = false): ?array
    {
        $provider = $this->resolveProvider($site);

        if (null === $provider) {
            return null;
        }

        $primary = $this->primaryDefinition($provider, $site);
        [$currentRange, $previousRange] = $this->computeRanges($periodDays);
        $previousPeriodCovered = $this->previousPeriodCovered($provider, $site, $previousRange);

        if (null !== $level && $includeSubpages) {
            $pageUidsByAnchor = [];
            foreach ($this->pageUidRepository->descendantsAtLevel($site->getRootPageId(), $level) as $anchorUid) {
                $pageUidsByAnchor[$anchorUid] = $this->pageUidRepository->descendantsOf($anchorUid);
            }

            return $this->trendCalculator->rankedByValueGrouped($pageUidsByAnchor, $languageId, $primary, $currentRange, $previousRange, self::TOP_PAGES_LIMIT, $previousPeriodCovered);
        }

        $pageUids = null !== $level
            ? $this->pageUidRepository->descendantsAtLevel($site->getRootPageId(), $level)
            : $this->pageUidRepository->descendantsOf($site->getRootPageId());

        return $this->trendCalculator->rankedByValue($pageUids, $languageId, $primary, $currentRange, $previousRange, self::TOP_PAGES_LIMIT, $previousPeriodCovered);
    }

    /**
     * Which provider ends up serving a given site is already resolved
     * automatically from site configuration (see resolveProvider()) - callers
     * pass the sites the resulting list needs to cover ($sites), so an
     * installation only ever using one provider never has to show it, even
     * though both Matomo and GA4 support classes are always registered
     * (see Configuration/Services.yaml) regardless of which sites actually
     * use them. Within whatever providers are relevant, labels are only
     * prefixed with the owning provider's display name for the identifiers
     * that actually need it: different providers reuse the same label text
     * for several metrics (Matomo and GA4 both call metrics "Page views",
     * "Avg. time on page" and "Bounce rate"), which would otherwise leave
     * identical-looking, differently-behaving entries in the dropdown once
     * a site mix pulls in both. A label unique among the relevant providers
     * (e.g. Matomo's "Visits" vs. GA4's "Sessions") is shown as-is.
     *
     * @param array<Site> $sites restricts the result to providers supporting at least one of these sites
     *
     * @return array<string, string> metric identifier => label, across every relevant provider
     */
    public function availableMetrics(array $sites): array
    {
        $definitions = [];

        foreach ($this->relevantProviders($sites) as [$provider, $site]) {
            foreach ([...$this->metricDefinitionCollector->forSite($provider, $site), ...$provider->getRateMetricDefinitions()] as $definition) {
                $definitions[$definition->identifier] = ['provider' => $provider, 'label' => $definition->label];
            }
        }

        $labelCounts = array_count_values(array_column($definitions, 'label'));

        $metrics = [];

        foreach ($definitions as $identifier => $definition) {
            $metrics[$identifier] = $labelCounts[$definition['label']] > 1
                ? sprintf('%s: %s', $this->providerDisplayName($definition['provider']), $definition['label'])
                : $definition['label'];
        }

        return $metrics;
    }

    /**
     * Whether the period every row is ranked against was imported at all.
     * A widget must not report a page as a riser because the days it is
     * compared with were never fetched, which is the same judgement the
     * page-module tiles make (see ImportCoverage::covers()).
     */
    private function previousPeriodCovered(MetricProviderInterface $provider, Site $site, DateRange $previousRange): bool
    {
        return $this->importCoverage->covers(
            $this->importCoverage->forProvider($provider->getIdentifier(), $site->getIdentifier()),
            $previousRange,
        );
    }

    /**
     * Each relevant provider with the first of $sites it supports. The site
     * comes along because a provider's metric list can depend on it (see
     * MetricDefinitionCollector): a provider serving several sites therefore
     * contributes the goals of the first one to the dropdown, which is the
     * same provider-level approximation availableMetrics() already makes by
     * listing one provider once.
     *
     * @param array<Site> $sites
     *
     * @return list<array{0: MetricProviderInterface, 1: Site}>
     */
    private function relevantProviders(array $sites): array
    {
        $relevant = [];

        foreach ($this->providers as $provider) {
            foreach ($sites as $site) {
                if ($provider->supports($site)) {
                    $relevant[] = [$provider, $site];

                    continue 2;
                }
            }
        }

        return $relevant;
    }

    /**
     * MetricProviderInterface has no dedicated display-name method - adding
     * one would be a breaking change for third-party providers (the interface
     * is @api, see AnnotationProviderInterface's sibling comment) for the sake
     * of cosmetics only relevant when several providers are mixed. First-party
     * providers get a proper name here; anything else falls back to a
     * capitalized identifier rather than failing.
     */
    private function providerDisplayName(MetricProviderInterface $provider): string
    {
        return match ($provider->getIdentifier()) {
            'matomo' => 'Matomo',
            'ga4' => 'GA4',
            default => ucfirst($provider->getIdentifier()),
        };
    }

    private function resolveProvider(Site $site): ?MetricProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($site)) {
                return $provider;
            }
        }

        return null;
    }

    private function primaryDefinition(MetricProviderInterface $provider, Site $site): MetricDefinition
    {
        $definitions = $this->metricDefinitionCollector->forSite($provider, $site);
        usort($definitions, static fn (MetricDefinition $a, MetricDefinition $b): int => $a->sortOrder <=> $b->sortOrder);

        return $definitions[0];
    }

    /**
     * Resolves $preferredMetricIdentifier against $provider's own metrics
     * (count and rate alike) - null when no identifier was requested, or
     * the requested one does not belong to this site's own provider (e.g. a
     * chart widget configured for a Matomo metric on a GA4-only site),
     * both cases falling back to the primary metric in build().
     */
    private function resolveChartMetric(MetricProviderInterface $provider, Site $site, ?string $preferredMetricIdentifier): MetricDefinition|RateMetricDefinition|null
    {
        if (null === $preferredMetricIdentifier || '' === $preferredMetricIdentifier) {
            return null;
        }

        foreach ($this->metricDefinitionCollector->forSite($provider, $site) as $definition) {
            if ($definition->identifier === $preferredMetricIdentifier) {
                return $definition;
            }
        }

        foreach ($provider->getRateMetricDefinitions() as $definition) {
            if ($definition->identifier === $preferredMetricIdentifier) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @param list<int> $pageUids
     *
     * @return array<string, int|null> Y-m-d => value, null for days with no data
     */
    private function buildChartSeries(MetricDefinition|RateMetricDefinition $metric, array $pageUids, int $languageId, DateRange $range, ?DateRange $coverage): array
    {
        $series = $metric instanceof MetricDefinition
            ? $this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $metric->identifier, $range)
            : $this->rateSeriesCalculator->series(
                $this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $metric->numerator, $range),
                $this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $metric->denominator, $range),
                $metric->unit,
            );

        return $this->importCoverage->mask($series, $coverage);
    }

    /**
     * Resolves each of $metricIdentifiers against $provider the same way the
     * primary chart metric is resolved (see resolveChartMetric()) - an
     * identifier not belonging to this site's own provider, or a duplicate of
     * one already resolved, is silently dropped rather than surfaced as an
     * error, matching the existing single-metric fallback behaviour.
     *
     * @param list<string> $metricIdentifiers
     * @param list<int>    $pageUids
     *
     * @return list<ChartSeries>
     */
    private function buildAdditionalChartSeries(MetricProviderInterface $provider, Site $site, array $metricIdentifiers, array $pageUids, int $languageId, DateRange $range, ?DateRange $coverage): array
    {
        $series = [];
        $seenIdentifiers = [];

        foreach ($metricIdentifiers as $identifier) {
            if (in_array($identifier, $seenIdentifiers, true)) {
                continue;
            }

            $metric = $this->resolveChartMetric($provider, $site, $identifier);

            if (null === $metric) {
                continue;
            }

            $seenIdentifiers[] = $identifier;
            $series[] = new ChartSeries($metric->label, $metric->color, $metric->unit, $this->buildChartSeries($metric, $pageUids, $languageId, $range, $coverage));
        }

        return $series;
    }

    /**
     * Combines the imported (e.g. Matomo) annotations with the ones editors
     * create directly in the backend (see ManualAnnotationRepository) into
     * one date-ordered list - callers never need to know an annotation can
     * come from two different places.
     *
     * Site-wide, so no page filter: a dashboard covers the whole site and a
     * marker scoped to one page still explains a bump in the site's totals.
     *
     * The category filter is applied here rather than in SQL. The set is
     * bounded by the retention window and is small enough that a second
     * query shape would cost more to maintain than it saves.
     *
     * @return list<Annotation>
     */
    private function buildAnnotations(string $siteIdentifier, DateRange $range, ?AnnotationCategory $category = null): array
    {
        $annotations = [
            ...$this->annotationRepository->forSiteAndRange($siteIdentifier, $range),
            ...$this->manualAnnotationRepository->forSiteAndRange($siteIdentifier, $range),
        ];

        if (null !== $category) {
            $annotations = array_values(array_filter(
                $annotations,
                static fn (Annotation $annotation): bool => $annotation->category === $category,
            ));
        }

        usort($annotations, static fn (Annotation $a, Annotation $b): int => $a->date <=> $b->date);

        return $annotations;
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
