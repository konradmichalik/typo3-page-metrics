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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricGroup, MetricPolarity, MetricUnit};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange, MetricDefinition, MetricTile, MetricTrend, PageMetricsViewData, RateMetricDefinition, SiteBenchmarkResult, TileBenchmark};
use KonradMichalik\Typo3PageMetrics\Event\ModifyMetricTilesEvent;
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, ManualAnnotationRepository, SitePageUidRepository, UnresolvedUrlRepository};
use KonradMichalik\Typo3PageMetrics\Provider\MetricProviderInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

use function in_array;
use function sprintf;

/**
 * PageMetricsViewDataBuilder.
 *
 * Extracted from the extension's former standalone backend module so the
 * page-header event listener and any future consumer share one tested source
 * of truth for what a page's metrics view looks like.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PageMetricsViewDataBuilder
{
    /** @var list<int> */
    private const PERIOD_OPTIONS = [7, 30, 90];

    private const LL = 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:';

    /**
     * The summary is assembled by this class rather than declared by a
     * provider, so it needs an identifier of its own. Not a real metric
     * identifier: nothing is ever stored under it, and it exists so the
     * client can tell this tile apart from the ones that stand for a single
     * stored metric.
     */
    private const MACHINE_SUMMARY_IDENTIFIER = 'machine.summary';

    /**
     * Grey on purpose. Every audience tile carries a colour of its own, and
     * giving machine traffic one of the same weight would put it back on the
     * footing this tile exists to take it off.
     */
    private const MACHINE_SUMMARY_COLOR = '#868e96';

    /**
     * Page types with no frontend content of their own - a folder, an
     * external URL/shortcut, or a spacer never receives visits, so metrics
     * tiles have nothing to show for them.
     *
     * @var list<int>
     */
    private const EXCLUDED_DOKTYPES = [
        PageRepository::DOKTYPE_LINK,
        PageRepository::DOKTYPE_SHORTCUT,
        PageRepository::DOKTYPE_SPACER,
        PageRepository::DOKTYPE_SYSFOLDER,
    ];

    /**
     * @param iterable<MetricProviderInterface> $providers
     */
    public function __construct(
        private SiteFinder $siteFinder,
        private DailyMetricsRepository $dailyMetricsRepository,
        private UnresolvedUrlRepository $unresolvedUrlRepository,
        private AnnotationRepository $annotationRepository,
        private ManualAnnotationRepository $manualAnnotationRepository,
        private SitePageUidRepository $pageUidRepository,
        private RateSeriesCalculator $rateSeriesCalculator,
        private ImportCoverage $importCoverage,
        private SiteBenchmark $siteBenchmark,
        private MetricValueFormatter $valueFormatter,
        private DataFreshnessResolver $dataFreshness,
        private ManualAnnotationLinkBuilder $annotationLinkBuilder,
        private EventDispatcherInterface $eventDispatcher,
        private iterable $providers,
    ) {}

    /**
     * $withBenchmark is off for callers that render synchronously. The
     * comparison costs one aggregate over every page of the site per metric,
     * which the page module can afford because its tiles arrive through an
     * AJAX route, and a dashboard widget cannot because its does not.
     */
    public function build(int $pageId, int $languageId, int $requestedPeriodDays, bool $includeSubpages = false, bool $withBenchmark = true): PageMetricsViewData
    {
        if ($pageId > 0) {
            $pageRow = BackendUtility::readPageAccess($pageId, $this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW));

            if (false === $pageRow) {
                // Backend user has no PAGE_SHOW permission on this page (e.g.
                // it sits outside their web mount) - degrade to the neutral
                // "no page selected" state instead of leaking metrics for a
                // page they cannot access.
                $pageId = 0;
            } elseif (in_array((int) $pageRow['doktype'], self::EXCLUDED_DOKTYPES, true)) {
                $pageId = 0;
            }
        }

        $periodDays = in_array($requestedPeriodDays, self::PERIOD_OPTIONS, true) ? $requestedPeriodDays : 30;
        $site = $this->resolveSite($pageId);
        $hasPage = $pageId > 0 && null !== $site;
        [$tileRange, $previousRange] = $this->computeRanges($periodDays);

        // Everything below this point needs a site. Collected in one branch
        // rather than as a condition per field, which is the same decision
        // asked five times over.
        $tiles = [];
        $unresolvedCount = 0;
        $annotations = [];
        $hasSubpages = false;
        $dataFreshness = null;
        $addAnnotationUrl = null;

        if (null !== $site) {
            $tiles = $this->buildTiles($site, $pageId, $languageId, $tileRange, $previousRange, $includeSubpages, $withBenchmark);
            $unresolvedCount = $this->unresolvedUrlRepository->countForSite($site->getBase()->getHost(), (new DateTimeImmutable('today'))->modify('-30 days'));
            $annotations = $this->buildAnnotations($site->getIdentifier(), $tileRange, $pageId);
            $hasSubpages = $this->pageUidRepository->hasChildren($pageId);
            $dataFreshness = $this->dataFreshness->forSite($site, $tileRange->until);
            $addAnnotationUrl = $this->annotationLinkBuilder->forPage($site, $pageId, $tileRange->until);
        }

        return new PageMetricsViewData(
            $hasPage,
            $pageId,
            $tiles,
            $unresolvedCount,
            $periodDays,
            self::PERIOD_OPTIONS,
            $this->formatRangeLabel($tileRange),
            $this->formatRangeLabel($previousRange),
            $annotations,
            $includeSubpages,
            $hasSubpages,
            $dataFreshness,
            $addAnnotationUrl,
        );
    }

    /**
     * Combines the imported (e.g. Matomo) annotations with the ones editors
     * create directly in the backend (see ManualAnnotationRepository) into
     * one date-ordered list - callers never need to know an annotation can
     * come from two different places.
     *
     * @return list<Annotation>
     */
    private function buildAnnotations(string $siteIdentifier, DateRange $range, int $pageUid): array
    {
        // Scoped to this page: the site-wide markers, this page's own, and
        // an ancestor's where it said it covers its subtree. Before markers
        // could name a page every one of them appeared on every tile, which
        // is why the site-wide ones keep doing exactly that.
        $ancestors = $this->pageUidRepository->ancestorsOf($pageUid);

        $annotations = [
            ...$this->annotationRepository->forSiteAndRange($siteIdentifier, $range, $pageUid, $ancestors),
            ...$this->manualAnnotationRepository->forSiteAndRange($siteIdentifier, $range, $pageUid, $ancestors),
        ];

        usort($annotations, static fn (Annotation $a, Annotation $b): int => $a->date <=> $b->date);

        return $annotations;
    }

    /**
     * @return array{0: DateRange, 1: DateRange}
     */
    private function computeRanges(int $periodDays): array
    {
        $today = new DateTimeImmutable('today');
        $tileRange = new DateRange($today->modify(sprintf('-%d days', $periodDays - 1)), $today);
        $previousRangeUntil = $tileRange->from->modify('-1 day');
        $previousRange = new DateRange($previousRangeUntil->modify(sprintf('-%d days', $periodDays - 1)), $previousRangeUntil);

        return [$tileRange, $previousRange];
    }

    /**
     * The raw comparison with the tile's own unit applied to it, or nothing at
     * all. SiteBenchmark answers in the metric's integer units, and a median
     * of 183 has to read "3:03" on a duration tile exactly as the headline
     * beside it does.
     */
    private function tileBenchmark(?SiteBenchmarkResult $benchmark, MetricUnit $unit): ?TileBenchmark
    {
        return null === $benchmark ? null : new TileBenchmark(
            $this->valueFormatter->format($benchmark->median, $unit),
            $benchmark->band,
            $benchmark->pageCount,
        );
    }

    private function formatRangeLabel(DateRange $range): string
    {
        return sprintf('%s – %s', $range->from->format('Y-m-d'), $range->until->format('Y-m-d'));
    }

    /**
     * @return list<MetricTile>
     */
    private function buildTiles(Site $site, int $pageId, int $languageId, DateRange $tileRange, DateRange $previousRange, bool $includeSubpages, bool $withBenchmark): array
    {
        // No external link when aggregating: it deep-links into Matomo
        // filtered by a single page's URL (segment=pageUrl==...), and
        // there's no equivalent for an arbitrary page subtree without a
        // different segment filtering scheme entirely - not resolving a
        // page URL at all is what makes collectDefinitions() skip it.
        // No benchmark while the tiles aggregate a subtree: the number on one
        // is then a section total, and the field it would be measured against
        // is made of individual pages. Comparing the two would say a section
        // is in the top fifth of the pages it contains.
        $withBenchmark = $withBenchmark && !$includeSubpages;

        $pageUrl = $includeSubpages ? null : $this->resolvePageUrl($site, $pageId);
        $pageUids = $includeSubpages ? $this->pageUidRepository->descendantsOf($pageId) : [$pageId];
        [$definitions, $rateDefinitions, $externalLinkByIdentifier, $coverageByIdentifier, $machineDefinitionsByProvider] = $this->collectDefinitions($site, $pageUrl, $tileRange);

        /** @var list<array{sortOrder: int, tile: MetricTile}> $ordered */
        $ordered = [];

        foreach ($definitions as $definition) {
            $ordered[] = [
                'sortOrder' => $definition->sortOrder,
                'tile' => $this->buildCountTile($definition, $site, $pageId, $pageUids, $languageId, $tileRange, $previousRange, $externalLinkByIdentifier[$definition->identifier] ?? null, $coverageByIdentifier[$definition->identifier] ?? null, $includeSubpages, $withBenchmark),
            ];
        }

        foreach ($machineDefinitionsByProvider as $providerIdentifier => $machineDefinitions) {
            $ordered[] = [
                // Where the provider's earliest machine metric would have sat,
                // so the summary keeps the place the buckets had rather than
                // being pushed to an end the provider never asked for. The
                // lowest sortOrder, not the first declared: nothing obliges a
                // provider to list them in order.
                'sortOrder' => min(array_column($machineDefinitions, 'sortOrder')),
                'tile' => $this->buildMachineSummaryTile($providerIdentifier, $machineDefinitions, $pageUids, $languageId, $tileRange, $previousRange, $coverageByIdentifier[$machineDefinitions[0]->identifier] ?? null),
            ];
        }

        foreach ($rateDefinitions as $rateDefinition) {
            $ordered[] = [
                'sortOrder' => $rateDefinition->sortOrder,
                'tile' => $this->buildRateTile($rateDefinition, $site, $pageId, $pageUids, $languageId, $tileRange, $previousRange, $externalLinkByIdentifier[$rateDefinition->identifier] ?? null, $coverageByIdentifier[$rateDefinition->identifier] ?? null, $includeSubpages, $withBenchmark),
            ];
        }

        usort($ordered, static fn (array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder']);

        $tiles = array_map(static fn (array $entry): MetricTile => $entry['tile'], $ordered);

        // Lets any extension contribute a tile without implementing the full
        // MetricProviderInterface contract - appended after the built-in tiles,
        // in listener registration order, rather than merged into the sort
        // above (a listener-added tile has no MetricDefinition::$sortOrder).
        // sparklineRange is kept as its own event property for API stability,
        // but its value is now always the same as tileRange: the sparkline
        // shows exactly the selected period, not a fixed separate window.
        $event = new ModifyMetricTilesEvent($tiles, $site, $pageId, $languageId, $tileRange, $previousRange, $tileRange);
        $this->eventDispatcher->dispatch($event);

        return $event->getTiles();
    }

    /**
     * Machine metrics come back separately rather than mixed into the first
     * list: they are not tiles, they are the parts of one, and keeping them
     * apart here means the tile loop stays a plain one-definition-one-tile
     * mapping instead of carrying a guard for the exception.
     *
     * Grouped by provider, because that is what a summary tile stands for.
     * Deriving the owner from the identifier would not work - the bundled
     * buckets are called bot.*, not accesslog.* - and guessing it is exactly
     * the fragility the provider tagging exists to avoid.
     *
     * @return array{0: list<MetricDefinition>, 1: list<RateMetricDefinition>, 2: array<string, ?string>, 3: array<string, ?DateRange>, 4: array<string, non-empty-list<MetricDefinition>>}
     */
    private function collectDefinitions(Site $site, ?string $pageUrl, DateRange $tileRange): array
    {
        /** @var array<string, MetricDefinition> $definitionsByIdentifier */
        $definitionsByIdentifier = [];
        /** @var array<string, RateMetricDefinition> $rateDefinitionsByIdentifier */
        $rateDefinitionsByIdentifier = [];
        /** @var array<string, ?string> $externalLinkByIdentifier */
        $externalLinkByIdentifier = [];
        // Coverage is a property of the provider that fetched the metric,
        // never of the site: an access log added to a site Matomo has been
        // feeding for a year has its own, much shorter history.
        /** @var array<string, ?DateRange> $coverageByIdentifier */
        $coverageByIdentifier = [];
        /** @var array<string, non-empty-list<MetricDefinition>> $machineDefinitionsByProvider */
        $machineDefinitionsByProvider = [];

        foreach ($this->providers as $provider) {
            if (!$provider->supports($site)) {
                continue;
            }

            $externalLink = null !== $pageUrl ? $provider->getExternalLink($site, $pageUrl, $tileRange) : null;
            $coverage = $this->importCoverage->forProvider($provider->getIdentifier(), $site->getIdentifier());

            foreach ($provider->getMetricDefinitions() as $definition) {
                $externalLinkByIdentifier[$definition->identifier] = $externalLink;
                $coverageByIdentifier[$definition->identifier] = $coverage;

                if (MetricGroup::Machine === $definition->group) {
                    $machineDefinitionsByProvider[$provider->getIdentifier()][] = $definition;

                    continue;
                }

                $definitionsByIdentifier[$definition->identifier] = $definition;
            }

            foreach ($provider->getRateMetricDefinitions() as $rateDefinition) {
                $rateDefinitionsByIdentifier[$rateDefinition->identifier] = $rateDefinition;
                $externalLinkByIdentifier[$rateDefinition->identifier] = $externalLink;
                $coverageByIdentifier[$rateDefinition->identifier] = $coverage;
            }
        }

        return [array_values($definitionsByIdentifier), array_values($rateDefinitionsByIdentifier), $externalLinkByIdentifier, $coverageByIdentifier, $machineDefinitionsByProvider];
    }

    private function resolvePageUrl(Site $site, int $pageId): ?string
    {
        try {
            return (string) $site->getRouter()->generateUri($pageId, [], '', RouterInterface::ABSOLUTE_URL);
            // @codeCoverageIgnoreStart
            // generateUri() declares InvalidRouteArgumentsException, but no
            // state reachable from a test produces it: verified against an
            // existing page, a folder, a non-existent uid, a negative uid,
            // uid 0 and a site without language configuration - every one
            // returns a URL. The guard is for third-party route enhancers,
            // which a functional test cannot install, so the two lines below
            // are excluded from coverage rather than left permanently red.
        } catch (Throwable) {
            return null;
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @param list<int> $pageUids
     */
    private function buildCountTile(MetricDefinition $definition, Site $site, int $pageId, array $pageUids, int $languageId, DateRange $tileRange, DateRange $previousRange, ?string $externalLink, ?DateRange $coverage, bool $includeSubpages, bool $withBenchmark): MetricTile
    {
        $series = $this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $definition->identifier, $tileRange);
        $sparkline = $this->importCoverage->mask($series, $coverage);
        $value = array_sum($series);
        $previousValue = array_sum($this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $definition->identifier, $previousRange));
        $redirectValue = array_sum($this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $definition->identifier, $tileRange, 'via_redirect'));

        $metricTrend = MetricTrend::between($value, $previousValue);
        $deltaPercent = $metricTrend->deltaPercent;
        $trend = $metricTrend->direction;
        $redirectSharePercent = $value > 0 ? (int) round($redirectValue / $value * 100) : 0;
        $benchmark = $withBenchmark ? $this->siteBenchmark->forCountMetric($site, $pageId, $languageId, $definition, $tileRange, $value) : null;

        return new MetricTile(
            $definition->identifier,
            $definition->label,
            $definition->description,
            $definition->icon,
            $definition->color,
            $definition->unit,
            $value,
            $this->valueFormatter->format($value, $definition->unit),
            $sparkline,
            $deltaPercent,
            $trend,
            $redirectSharePercent,
            $externalLink,
            null !== $deltaPercent ? $this->valueFormatter->format($previousValue, $definition->unit) : '',
            $definition->polarity,
            // No breakdown: a tile that stands for one metric has nothing to
            // break down. Only the machine-traffic summary does.
            [],
            $this->tileBenchmark($benchmark, $definition->unit),
        );
    }

    /**
     * One tile standing for a provider's whole machine-traffic set, with the
     * buckets it is made of carried along for its popover.
     *
     * Summed rather than picked: the buckets are disjoint counts of the same
     * thing, so their total is the number an editor wants ("how much of this
     * was not a person") and the split is the follow-up question, not the
     * headline. The daily series are summed the same way, so the sparkline
     * describes the total the tile shows rather than whichever bucket
     * happened to be first.
     *
     * @param non-empty-list<MetricDefinition> $definitions
     * @param list<int>                        $pageUids
     */
    private function buildMachineSummaryTile(string $providerIdentifier, array $definitions, array $pageUids, int $languageId, DateRange $tileRange, DateRange $previousRange, ?DateRange $coverage): MetricTile
    {
        $series = [];
        $previousValue = 0;
        $redirectValue = 0;
        $breakdown = [];

        foreach ($definitions as $definition) {
            [$series, $previousValue, $redirectValue, $breakdown] = $this->foldBucket(
                $definition,
                $pageUids,
                $languageId,
                $tileRange,
                $previousRange,
                $series,
                $previousValue,
                $redirectValue,
                $breakdown,
            );
        }

        $value = array_sum($series);
        $metricTrend = MetricTrend::between($value, $previousValue);
        $deltaPercent = $metricTrend->deltaPercent;
        $trend = $metricTrend->direction;
        $redirectSharePercent = $value > 0 ? (int) round($redirectValue / $value * 100) : 0;
        $languageService = $this->getLanguageService();

        return new MetricTile(
            // Per provider, not one identifier for all of them: two providers
            // declaring machine metrics would otherwise produce two tiles that
            // are indistinguishable to a reader and ambiguous to anything that
            // looks a tile up by identifier.
            self::MACHINE_SUMMARY_IDENTIFIER.'.'.$providerIdentifier,
            $languageService->sL(self::LL.'metric.machine.label'),
            $languageService->sL(self::LL.'metric.machine.description'),
            'tabler-robot',
            self::MACHINE_SUMMARY_COLOR,
            MetricUnit::Count,
            $value,
            $this->valueFormatter->format($value, MetricUnit::Count),
            $this->importCoverage->mask($series, $coverage),
            $deltaPercent,
            $trend,
            $redirectSharePercent,
            // No external link: the access log has no interface of its own to
            // deep-link into, and a summary spanning several metrics has no
            // single thing to point at even where a provider does.
            null,
            null !== $deltaPercent ? $this->valueFormatter->format($previousValue, MetricUnit::Count) : '',
            // Neutral throughout: more crawler traffic is neither an
            // achievement nor a failure, and colouring it green or red would
            // tell an editor to act on something they cannot influence.
            MetricPolarity::Neutral,
            $breakdown,
        );
    }

    /**
     * One bucket's contribution to the summary: its days added into the
     * running series, its totals added to the running previous and
     * via-redirect sums, and its own figure appended to the breakdown.
     *
     * The via-redirect share is summed across the buckets like the headline
     * is. The import writes a via_redirect row for every metric, and folding
     * the buckets together would otherwise drop that share in silence.
     *
     * @param list<int>                                          $pageUids
     * @param array<string, int>                                 $series
     * @param list<array{label: string, formattedValue: string}> $breakdown
     *
     * @return array{0: array<string, int>, 1: int, 2: int, 3: list<array{label: string, formattedValue: string}>}
     */
    private function foldBucket(MetricDefinition $definition, array $pageUids, int $languageId, DateRange $tileRange, DateRange $previousRange, array $series, int $previousValue, int $redirectValue, array $breakdown): array
    {
        $bucketSeries = $this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $definition->identifier, $tileRange);

        foreach ($bucketSeries as $date => $value) {
            $series[$date] = ($series[$date] ?? 0) + $value;
        }

        $breakdown[] = [
            'label' => $definition->label,
            'formattedValue' => $this->valueFormatter->format(array_sum($bucketSeries), $definition->unit),
        ];

        return [
            $series,
            $previousValue + array_sum($this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $definition->identifier, $previousRange)),
            $redirectValue + array_sum($this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $definition->identifier, $tileRange, 'via_redirect')),
            $breakdown,
        ];
    }

    /**
     * @param list<int> $pageUids
     */
    private function buildRateTile(RateMetricDefinition $definition, Site $site, int $pageId, array $pageUids, int $languageId, DateRange $tileRange, DateRange $previousRange, ?string $externalLink, ?DateRange $coverage, bool $includeSubpages, bool $withBenchmark): MetricTile
    {
        $numeratorSparkline = $this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $definition->numerator, $tileRange);
        $denominatorSparkline = $this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $definition->denominator, $tileRange);
        $sparkline = $this->importCoverage->mask($this->rateSeriesCalculator->series($numeratorSparkline, $denominatorSparkline, $definition->unit), $coverage);

        $value = $this->rateSeriesCalculator->ratio(
            array_sum($numeratorSparkline),
            array_sum($denominatorSparkline),
            $definition->unit,
        );
        $previousValue = $this->rateSeriesCalculator->ratio(
            array_sum($this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $definition->numerator, $previousRange)),
            array_sum($this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $definition->denominator, $previousRange)),
            $definition->unit,
        );

        $metricTrend = MetricTrend::between($value, $previousValue);
        $deltaPercent = $metricTrend->deltaPercent;
        $trend = $metricTrend->direction;
        $benchmark = $withBenchmark ? $this->siteBenchmark->forRateMetric($site, $pageId, $languageId, $definition, $tileRange, $value) : null;

        return new MetricTile(
            $definition->identifier,
            $definition->label,
            $definition->description,
            $definition->icon,
            $definition->color,
            $definition->unit,
            $value,
            $this->valueFormatter->format($value, $definition->unit),
            $sparkline,
            $deltaPercent,
            $trend,
            0,
            $externalLink,
            null !== $deltaPercent ? $this->valueFormatter->format($previousValue, $definition->unit) : '',
            $definition->polarity,
            // No breakdown: a tile that stands for one metric has nothing to
            // break down. Only the machine-traffic summary does.
            [],
            $this->tileBenchmark($benchmark, $definition->unit),
        );
    }

    private function resolveSite(int $pageId): ?Site
    {
        if ($pageId < 1) {
            return null;
        }

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
