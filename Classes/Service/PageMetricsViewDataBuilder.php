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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricUnit, TrendDirection};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DataFreshness, DateRange, MetricDefinition, MetricTile, PageMetricsViewData, RateMetricDefinition};
use KonradMichalik\Typo3PageMetrics\Event\ModifyMetricTilesEvent;
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, ImportStateRepository, ManualAnnotationRepository, SitePageUidRepository, UnresolvedUrlRepository};
use KonradMichalik\Typo3PageMetrics\Provider\MetricProviderInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

use function in_array;
use function intdiv;
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
        private ImportStateRepository $importStateRepository,
        private EventDispatcherInterface $eventDispatcher,
        private iterable $providers,
    ) {}

    public function build(int $pageId, int $languageId, int $requestedPeriodDays, bool $includeSubpages = false): PageMetricsViewData
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

        if (null !== $site) {
            $tiles = $this->buildTiles($site, $pageId, $languageId, $tileRange, $previousRange, $includeSubpages);
            $unresolvedCount = $this->unresolvedUrlRepository->countForSite($site->getBase()->getHost(), (new DateTimeImmutable('today'))->modify('-30 days'));
            $annotations = $this->buildAnnotations($site->getIdentifier(), $tileRange);
            $hasSubpages = $this->pageUidRepository->hasChildren($pageId);
            $dataFreshness = $this->resolveDataFreshness($site, $tileRange->until);
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
        );
    }

    /**
     * The tile row is only as fresh as its stalest number, so a site whose
     * providers are at different days reports the earliest of them rather
     * than flattering itself with the newest.
     *
     * A provider that has never imported contributes no day at all: its
     * tiles are empty, which is visible on its own, and letting it veto the
     * whole row's date would hide the freshness of the providers that do
     * work. Only when no provider has ever run is the answer unknown.
     *
     * Read from the resume cursor, not from the covered window: the cursor
     * is the last day the import considers complete, which is what "the
     * numbers are current up to here" means, and it is recorded by every run
     * including one that returned nothing. The covered window can be unknown
     * on a perfectly healthy installation, and a missing date is exactly the
     * wrong answer to give about a scheduler that may have stopped.
     */
    private function resolveDataFreshness(Site $site, DateTimeImmutable $today): ?DataFreshness
    {
        $earliest = null;

        foreach ($this->providers as $provider) {
            if (!$provider->supports($site)) {
                continue;
            }

            $lastImported = $this->importStateRepository->getLastImportedDate($provider->getIdentifier(), $site->getIdentifier());

            if (null !== $lastImported && (null === $earliest || $lastImported < $earliest)) {
                $earliest = $lastImported;
            }
        }

        return null !== $earliest ? new DataFreshness($earliest, $today) : null;
    }

    /**
     * Combines the imported (e.g. Matomo) annotations with the ones editors
     * create directly in the backend (see ManualAnnotationRepository) into
     * one date-ordered list - callers never need to know an annotation can
     * come from two different places.
     *
     * @return list<Annotation>
     */
    private function buildAnnotations(string $siteIdentifier, DateRange $range): array
    {
        $annotations = [
            ...$this->annotationRepository->forSiteAndRange($siteIdentifier, $range),
            ...$this->manualAnnotationRepository->forSiteAndRange($siteIdentifier, $range),
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

    private function formatRangeLabel(DateRange $range): string
    {
        return sprintf('%s – %s', $range->from->format('Y-m-d'), $range->until->format('Y-m-d'));
    }

    /**
     * @return list<MetricTile>
     */
    private function buildTiles(Site $site, int $pageId, int $languageId, DateRange $tileRange, DateRange $previousRange, bool $includeSubpages): array
    {
        // No external link when aggregating: it deep-links into Matomo
        // filtered by a single page's URL (segment=pageUrl==...), and
        // there's no equivalent for an arbitrary page subtree without a
        // different segment filtering scheme entirely - not resolving a
        // page URL at all is what makes collectDefinitions() skip it.
        $pageUrl = $includeSubpages ? null : $this->resolvePageUrl($site, $pageId);
        $pageUids = $includeSubpages ? $this->pageUidRepository->descendantsOf($pageId) : [$pageId];
        [$definitions, $rateDefinitions, $externalLinkByIdentifier, $coverageByIdentifier] = $this->collectDefinitions($site, $pageUrl, $tileRange);

        /** @var list<array{sortOrder: int, tile: MetricTile}> $ordered */
        $ordered = [];

        foreach ($definitions as $definition) {
            $ordered[] = [
                'sortOrder' => $definition->sortOrder,
                'tile' => $this->buildCountTile($definition, $pageUids, $languageId, $tileRange, $previousRange, $externalLinkByIdentifier[$definition->identifier] ?? null, $coverageByIdentifier[$definition->identifier] ?? null),
            ];
        }

        foreach ($rateDefinitions as $rateDefinition) {
            $ordered[] = [
                'sortOrder' => $rateDefinition->sortOrder,
                'tile' => $this->buildRateTile($rateDefinition, $pageUids, $languageId, $tileRange, $previousRange, $externalLinkByIdentifier[$rateDefinition->identifier] ?? null, $coverageByIdentifier[$rateDefinition->identifier] ?? null),
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
     * @return array{0: list<MetricDefinition>, 1: list<RateMetricDefinition>, 2: array<string, ?string>, 3: array<string, ?DateRange>}
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

        foreach ($this->providers as $provider) {
            if (!$provider->supports($site)) {
                continue;
            }

            $externalLink = null !== $pageUrl ? $provider->getExternalLink($site, $pageUrl, $tileRange) : null;
            $coverage = $this->importCoverage->forProvider($provider->getIdentifier(), $site->getIdentifier());

            foreach ($provider->getMetricDefinitions() as $definition) {
                $definitionsByIdentifier[$definition->identifier] = $definition;
                $externalLinkByIdentifier[$definition->identifier] = $externalLink;
                $coverageByIdentifier[$definition->identifier] = $coverage;
            }

            foreach ($provider->getRateMetricDefinitions() as $rateDefinition) {
                $rateDefinitionsByIdentifier[$rateDefinition->identifier] = $rateDefinition;
                $externalLinkByIdentifier[$rateDefinition->identifier] = $externalLink;
                $coverageByIdentifier[$rateDefinition->identifier] = $coverage;
            }
        }

        return [array_values($definitionsByIdentifier), array_values($rateDefinitionsByIdentifier), $externalLinkByIdentifier, $coverageByIdentifier];
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
    private function buildCountTile(MetricDefinition $definition, array $pageUids, int $languageId, DateRange $tileRange, DateRange $previousRange, ?string $externalLink, ?DateRange $coverage): MetricTile
    {
        $series = $this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $definition->identifier, $tileRange);
        $sparkline = $this->importCoverage->mask($series, $coverage);
        $value = array_sum($series);
        $previousValue = array_sum($this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $definition->identifier, $previousRange));
        $redirectValue = array_sum($this->dailyMetricsRepository->siteSeries($pageUids, $languageId, $definition->identifier, $tileRange, 'via_redirect'));

        [$deltaPercent, $trend] = $this->computeTrend($value, $previousValue);
        $redirectSharePercent = $value > 0 ? (int) round($redirectValue / $value * 100) : 0;

        return new MetricTile(
            $definition->identifier,
            $definition->label,
            $definition->description,
            $definition->icon,
            $definition->color,
            $definition->unit,
            $value,
            $this->formatValue($value, $definition->unit),
            $sparkline,
            $deltaPercent,
            $trend,
            $redirectSharePercent,
            $externalLink,
            null !== $deltaPercent ? $this->formatValue($previousValue, $definition->unit) : '',
            $definition->polarity,
        );
    }

    /**
     * A bounce rate or an average duration is a ratio, not a count: summing
     * daily ratios would weight a quiet day the same as a busy one. Instead
     * this divides the sum of the numerator by the sum of the denominator
     * over each range, for the sparkline's daily points as well as the
     * tile/previous period totals.
     */
    /**
     * @param list<int> $pageUids
     */
    private function buildRateTile(RateMetricDefinition $definition, array $pageUids, int $languageId, DateRange $tileRange, DateRange $previousRange, ?string $externalLink, ?DateRange $coverage): MetricTile
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

        [$deltaPercent, $trend] = $this->computeTrend($value, $previousValue);

        return new MetricTile(
            $definition->identifier,
            $definition->label,
            $definition->description,
            $definition->icon,
            $definition->color,
            $definition->unit,
            $value,
            $this->formatValue($value, $definition->unit),
            $sparkline,
            $deltaPercent,
            $trend,
            0,
            $externalLink,
            null !== $deltaPercent ? $this->formatValue($previousValue, $definition->unit) : '',
            $definition->polarity,
        );
    }

    private function formatValue(int $value, MetricUnit $unit): string
    {
        return match ($unit) {
            MetricUnit::Percent => sprintf('%d%%', $value),
            MetricUnit::Seconds => $this->formatDuration($value),
            MetricUnit::Count => (string) $value,
        };
    }

    private function formatDuration(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * @return array{0: ?int, 1: TrendDirection}
     */
    private function computeTrend(int $value, int $previousValue): array
    {
        if (0 === $previousValue) {
            // No meaningful percentage base: value > 0 is "new" (null percent,
            // still an upward trend), value === 0 is genuinely flat.
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
}
