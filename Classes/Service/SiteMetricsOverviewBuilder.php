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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{AnnotationCategory, MetricGroup, MetricUnit, TrendSentiment, UnresolvedReason};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DataFreshness, DateRange, MetricDefinition, RateMetricDefinition, SiteMetricsOverview, TopPage, UnresolvedUrlSummary};
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, ImportStateRepository, ManualAnnotationRepository, SitePageUidRepository, UnresolvedUrlRepository};
use KonradMichalik\Typo3PageMetrics\Provider\{MetricDefinitionCollector, MetricProviderInterface, SiteAwareMetricProviderInterface};
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
 * by the site's primary metric (see MetricDefinitionCollector::primaryFor(),
 * which is where every surface gets the same answer) - the chart can be
 * pointed at a different metric via $preferredMetricIdentifier.
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
        private MachineSummaryMetric $machineSummaryMetric,
        private iterable $providers,
    ) {}

    public function build(Site $site, int $languageId, int $periodDays, ?string $preferredMetricIdentifier = null, ?AnnotationCategory $annotationCategory = null): SiteMetricsOverview
    {
        $provider = $this->resolveProvider($site);

        if (null === $provider) {
            return new SiteMetricsOverview($site, false, '', '', '', MetricUnit::Count, [], [], [], [], null);
        }

        // A site that counts nothing gets the same answer as a site with no
        // provider at all, here and in the two builders below: there is no
        // figure to lead with, and every widget on top of this one is built
        // around one (see MetricDefinitionCollector::primaryFor()).
        $primary = $this->metricDefinitionCollector->primaryFor($this->providers, $site);

        if (null === $primary) {
            return new SiteMetricsOverview($site, false, '', '', '', MetricUnit::Count, [], [], [], [], null);
        }

        $chartMetric = $this->resolveChartMetric($provider, $site, $preferredMetricIdentifier) ?? $primary;

        [$currentRange, $previousRange] = $this->computeRanges($periodDays);
        $pageUids = $this->pageUidRepository->descendantsOf($site->getRootPageId());
        // The provider the charted metric belongs to, not the site's first.
        // Coverage is recorded per provider, so a site running the access log
        // for its bot metrics and Matomo for its audience would otherwise mask
        // matomo.pageviews against the access log's import window, which says
        // nothing about it.
        $coverage = $this->coverageFor($chartMetric->identifier, $site, $provider);

        return new SiteMetricsOverview(
            $site,
            true,
            $chartMetric->label,
            $chartMetric->icon,
            $chartMetric->color,
            $chartMetric->unit,
            $this->buildChartSeries($chartMetric, $provider, $site, $pageUids, $languageId, $currentRange, $coverage),
            $this->trendCalculator->rankedByValue($pageUids, $languageId, $primary, $currentRange, $previousRange, self::TOP_PAGES_LIMIT, $this->importCoverage->covers($coverage, $previousRange)),
            $this->buildUnresolvedUrls($site),
            $this->buildAnnotations($site->getIdentifier(), $currentRange, $annotationCategory),
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

        $primary = $this->metricDefinitionCollector->primaryFor($this->providers, $site);

        if (null === $primary) {
            return null;
        }

        $metric = $this->resolveChartMetric($provider, $site, $preferredMetricIdentifier) ?? $primary;
        [$currentRange, $previousRange] = $this->computeRanges($periodDays);
        $pageUids = $this->pageUidRepository->descendantsOf($site->getRootPageId());

        return $this->trendCalculator->rankedByTrend($pageUids, $languageId, $metric, $currentRange, $previousRange, $sentiment, $limit, $this->previousPeriodCovered($this->providerFor($metric->identifier, $site, $provider), $site, $previousRange));
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
     * $goalMetricIdentifier adds one of this site's own goal metrics (see
     * goalMetricsFor()) as a second value on every row, beside the traffic
     * the list is ranked by. The ranking axis stays the traffic either way:
     * a list titled "most viewed pages" that sorted by conversions would be
     * answering a different question under the same heading.
     *
     * @return ?list<TopPage> null when no provider supports $site
     */
    public function buildTopPagesForSite(Site $site, int $languageId, int $periodDays, ?int $level = null, bool $includeSubpages = false, ?string $goalMetricIdentifier = null): ?array
    {
        $provider = $this->resolveProvider($site);

        if (null === $provider) {
            return null;
        }

        $primary = $this->metricDefinitionCollector->primaryFor($this->providers, $site);

        if (null === $primary) {
            return null;
        }

        $goalMetric = $this->resolveGoalMetric($site, $goalMetricIdentifier);
        [$currentRange, $previousRange] = $this->computeRanges($periodDays);
        $previousPeriodCovered = $this->previousPeriodCovered($this->providerFor($primary->identifier, $site, $provider), $site, $previousRange);

        if (null !== $level && $includeSubpages) {
            $pageUidsByAnchor = [];
            foreach ($this->pageUidRepository->descendantsAtLevel($site->getRootPageId(), $level) as $anchorUid) {
                $pageUidsByAnchor[$anchorUid] = $this->pageUidRepository->descendantsOf($anchorUid);
            }

            return $this->trendCalculator->rankedByValueGrouped($pageUidsByAnchor, $languageId, $primary, $currentRange, $previousRange, self::TOP_PAGES_LIMIT, $previousPeriodCovered, $goalMetric);
        }

        $pageUids = null !== $level
            ? $this->pageUidRepository->descendantsAtLevel($site->getRootPageId(), $level)
            : $this->pageUidRepository->descendantsOf($site->getRootPageId());

        return $this->trendCalculator->rankedByValue($pageUids, $languageId, $primary, $currentRange, $previousRange, self::TOP_PAGES_LIMIT, $previousPeriodCovered, $goalMetric);
    }

    /**
     * The metrics one site has only because it declared goals for its
     * provider (see SiteAwareMetricProviderInterface), as the identifier =>
     * label map a widget setting is built from. Empty for a site with no
     * goals, which is what keeps a goal setting from being offered at all
     * where there is nothing to pick.
     *
     * @return array<string, string> metric identifier => the operator's own label for it
     */
    public function goalMetricsFor(Site $site): array
    {
        return array_map(static fn (MetricDefinition $definition): string => $definition->label, $this->goalDefinitionsFor($site));
    }

    /**
     * Every goal metric across $sites, for a widget setting that is declared
     * once and rendered against whichever sites the widget ends up covering.
     * A label is per site, so two sites declaring the same goal id under
     * different names collapse to one entry here - the column header itself
     * uses the site's own label (see goalMetricsFor()).
     *
     * @param array<Site> $sites
     *
     * @return array<string, string>
     */
    public function availableGoalMetrics(array $sites): array
    {
        // array_values() before the spread: SiteFinder keys its sites by
        // identifier, and spreading a string-keyed array into a call is
        // PHP's syntax for named arguments rather than for a variadic list.
        return array_merge([], ...array_values(array_map(fn (Site $site): array => $this->goalMetricsFor($site), $sites)));
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
     * @param array<Site>   $sites                  restricts the result to providers supporting at least one of these sites
     * @param bool          $includeMachineSummary  false for a widget that reads stored rows, since none exist for the summary
     * @param ?list<string> $machineMetricAllowList null keeps every MetricGroup::Machine metric, a list keeps only those
     *                                              (the breakdown widget only finds the ones with a tile of their own)
     *
     * @return array<string, string> metric identifier => label, across every relevant provider
     */
    public function availableMetrics(array $sites, bool $includeMachineSummary = true, ?array $machineMetricAllowList = null): array
    {
        $definitions = [];

        foreach ($this->relevantProviders($sites) as [$provider, $site]) {
            $offered = array_filter(
                [...$this->metricDefinitionCollector->forSite($provider, $site), ...$provider->getRateMetricDefinitions()],
                static fn (MetricDefinition|RateMetricDefinition $definition): bool => self::isOffered($definition, $machineMetricAllowList),
            );

            foreach ([...$offered, ...$this->offeredMachineSummary($provider, $site, $includeMachineSummary)] as $definition) {
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
     * One synthetic entry per provider with anything to sum, the same total
     * the tile's "Bot traffic" summary shows.
     *
     * @return list<MetricDefinition>
     */
    private function offeredMachineSummary(MetricProviderInterface $provider, Site $site, bool $includeMachineSummary): array
    {
        return $includeMachineSummary ? array_filter([$this->machineSummaryMetric->definitionFor($provider, $site)]) : [];
    }

    /**
     * @param ?list<string> $machineMetricAllowList
     */
    private static function isOffered(MetricDefinition|RateMetricDefinition $definition, ?array $machineMetricAllowList): bool
    {
        return null === $machineMetricAllowList
            || !$definition instanceof MetricDefinition
            || MetricGroup::Machine !== $definition->group
            || in_array($definition->identifier, $machineMetricAllowList, true);
    }

    /**
     * Whether the period every row is ranked against was imported at all.
     * A widget must not report a page as a riser because the days it is
     * compared with were never fetched, which is the same judgement the
     * page-module tiles make (see ImportCoverage::covers()).
     */
    /**
     * The provider that declares this metric, falling back to the site's
     * first where nothing claims it. Coverage and the previous-period gate
     * are recorded per provider, and the primary metric may come from a
     * provider that is not the first one configured (see
     * MetricDefinitionCollector::primaryFor()).
     */
    private function providerFor(string $identifier, Site $site, MetricProviderInterface $fallback): MetricProviderInterface
    {
        return $this->metricDefinitionCollector->providerOf($this->providers, $site, $identifier) ?? $fallback;
    }

    private function coverageFor(string $identifier, Site $site, MetricProviderInterface $fallback): ?DateRange
    {
        return $this->importCoverage->forProvider($this->providerFor($identifier, $site, $fallback)->getIdentifier(), $site->getIdentifier());
    }

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
        return $this->metricDefinitionCollector->supporting($this->providers, $site)[0] ?? null;
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

        if ($preferredMetricIdentifier === $this->machineSummaryMetric->identifierFor($provider)) {
            return $this->machineSummaryMetric->definitionFor($provider, $site);
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
     * Null for anything this site cannot speak for: no identifier asked
     * about, or one belonging to another site's goals (a widget instance
     * covers every site at once, and a goal is declared per site). Silently
     * dropped rather than surfaced as an error, the same fallback
     * resolveChartMetric() applies to a metric of another provider.
     */
    private function resolveGoalMetric(Site $site, ?string $identifier): ?MetricDefinition
    {
        return $this->goalDefinitionsFor($site)[$identifier ?? ''] ?? null;
    }

    /**
     * Keyed by identifier, which is how both callers look a goal up.
     *
     * Only the definitions a site brought about itself, never the fixed ones
     * every site of this provider has: a goal column offering "page views"
     * beside page views is not a choice anybody wants.
     *
     * @return array<string, MetricDefinition>
     */
    private function goalDefinitionsFor(Site $site): array
    {
        $provider = $this->resolveProvider($site);
        $definitions = $provider instanceof SiteAwareMetricProviderInterface ? $provider->getSiteMetricDefinitions($site) : [];

        return array_combine(array_column($definitions, 'identifier'), $definitions);
    }

    /**
     * @param list<int> $pageUids
     *
     * @return array<string, int|null> Y-m-d => value, null for days with no data
     */
    private function buildChartSeries(MetricDefinition|RateMetricDefinition $metric, MetricProviderInterface $provider, Site $site, array $pageUids, int $languageId, DateRange $range, ?DateRange $coverage): array
    {
        if ($metric instanceof MetricDefinition && $metric->identifier === $this->machineSummaryMetric->identifierFor($provider)) {
            return $this->importCoverage->mask($this->machineSummaryMetric->seriesFor($provider, $site, $pageUids, $languageId, $range), $coverage);
        }

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
