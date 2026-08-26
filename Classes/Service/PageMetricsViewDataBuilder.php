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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricUnit, TrendDirection};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, MetricTile, PageMetricsViewData, RateMetricDefinition};
use KonradMichalik\Typo3PageMetrics\Event\ModifyMetricTilesEvent;
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, UnresolvedUrlRepository};
use KonradMichalik\Typo3PageMetrics\Source\MetricSourceInterface;
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
final class PageMetricsViewDataBuilder
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
     * @param iterable<MetricSourceInterface> $sources
     */
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly DailyMetricsRepository $dailyMetricsRepository,
        private readonly UnresolvedUrlRepository $unresolvedUrlRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly iterable $sources,
    ) {}

    public function build(int $pageId, int $languageId, int $requestedPeriodDays): PageMetricsViewData
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

        $unresolvedCount = null !== $site
            ? $this->unresolvedUrlRepository->countForSite($site->getBase()->getHost(), (new DateTimeImmutable('today'))->modify('-30 days'))
            : 0;

        return new PageMetricsViewData(
            $hasPage,
            $pageId,
            null !== $site ? $this->buildTiles($site, $pageId, $languageId, $tileRange, $previousRange) : [],
            $unresolvedCount,
            $periodDays,
            self::PERIOD_OPTIONS,
            $this->formatRangeLabel($tileRange),
            $this->formatRangeLabel($previousRange),
        );
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
    private function buildTiles(Site $site, int $pageId, int $languageId, DateRange $tileRange, DateRange $previousRange): array
    {
        $pageUrl = $this->resolvePageUrl($site, $pageId);
        [$definitions, $rateDefinitions, $externalLinkByIdentifier] = $this->collectDefinitions($site, $pageUrl, $tileRange);

        /** @var list<array{sortOrder: int, tile: MetricTile}> $ordered */
        $ordered = [];

        foreach ($definitions as $definition) {
            $ordered[] = [
                'sortOrder' => $definition->sortOrder,
                'tile' => $this->buildCountTile($definition, $pageId, $languageId, $tileRange, $previousRange, $externalLinkByIdentifier[$definition->identifier] ?? null),
            ];
        }

        foreach ($rateDefinitions as $rateDefinition) {
            $ordered[] = [
                'sortOrder' => $rateDefinition->sortOrder,
                'tile' => $this->buildRateTile($rateDefinition, $pageId, $languageId, $tileRange, $previousRange, $externalLinkByIdentifier[$rateDefinition->identifier] ?? null),
            ];
        }

        usort($ordered, static fn (array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder']);

        $tiles = array_map(static fn (array $entry): MetricTile => $entry['tile'], $ordered);

        // Lets any extension contribute a tile without implementing the full
        // MetricSourceInterface contract - appended after the built-in tiles,
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
     * @return array{0: list<MetricDefinition>, 1: list<RateMetricDefinition>, 2: array<string, ?string>}
     */
    private function collectDefinitions(Site $site, ?string $pageUrl, DateRange $tileRange): array
    {
        /** @var array<string, MetricDefinition> $definitionsByIdentifier */
        $definitionsByIdentifier = [];
        /** @var array<string, RateMetricDefinition> $rateDefinitionsByIdentifier */
        $rateDefinitionsByIdentifier = [];
        /** @var array<string, ?string> $externalLinkByIdentifier */
        $externalLinkByIdentifier = [];

        foreach ($this->sources as $source) {
            if (!$source->supports($site)) {
                continue;
            }

            $externalLink = null !== $pageUrl ? $source->getExternalLink($site, $pageUrl, $tileRange) : null;

            foreach ($source->getMetricDefinitions() as $definition) {
                $definitionsByIdentifier[$definition->identifier] = $definition;
                $externalLinkByIdentifier[$definition->identifier] = $externalLink;
            }

            foreach ($source->getRateMetricDefinitions() as $rateDefinition) {
                $rateDefinitionsByIdentifier[$rateDefinition->identifier] = $rateDefinition;
                $externalLinkByIdentifier[$rateDefinition->identifier] = $externalLink;
            }
        }

        return [array_values($definitionsByIdentifier), array_values($rateDefinitionsByIdentifier), $externalLinkByIdentifier];
    }

    private function resolvePageUrl(Site $site, int $pageId): ?string
    {
        try {
            return (string) $site->getRouter()->generateUri($pageId, [], '', RouterInterface::ABSOLUTE_URL);
        } catch (Throwable) {
            return null;
        }
    }

    private function buildCountTile(MetricDefinition $definition, int $pageId, int $languageId, DateRange $tileRange, DateRange $previousRange, ?string $externalLink): MetricTile
    {
        $sparkline = $this->dailyMetricsRepository->dailySeries($pageId, $languageId, $definition->identifier, $tileRange);
        $value = array_sum($sparkline);
        $previousValue = array_sum($this->dailyMetricsRepository->dailySeries($pageId, $languageId, $definition->identifier, $previousRange));
        $redirectValue = array_sum($this->dailyMetricsRepository->dailySeries($pageId, $languageId, $definition->identifier, $tileRange, 'via_redirect'));

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
        );
    }

    /**
     * A bounce rate or an average duration is a ratio, not a count: summing
     * daily ratios would weight a quiet day the same as a busy one. Instead
     * this divides the sum of the numerator by the sum of the denominator
     * over each range, for the sparkline's daily points as well as the
     * tile/previous period totals.
     */
    private function buildRateTile(RateMetricDefinition $definition, int $pageId, int $languageId, DateRange $tileRange, DateRange $previousRange, ?string $externalLink): MetricTile
    {
        $numeratorSparkline = $this->dailyMetricsRepository->dailySeries($pageId, $languageId, $definition->numerator, $tileRange);
        $denominatorSparkline = $this->dailyMetricsRepository->dailySeries($pageId, $languageId, $definition->denominator, $tileRange);
        $sparkline = $this->buildRatioSeries($numeratorSparkline, $denominatorSparkline, $definition->unit);

        $value = $this->computeRatio(
            array_sum($numeratorSparkline),
            array_sum($denominatorSparkline),
            $definition->unit,
        );
        $previousValue = $this->computeRatio(
            array_sum($this->dailyMetricsRepository->dailySeries($pageId, $languageId, $definition->numerator, $previousRange)),
            array_sum($this->dailyMetricsRepository->dailySeries($pageId, $languageId, $definition->denominator, $previousRange)),
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
        );
    }

    /**
     * @param array<string, int> $numeratorSeries
     * @param array<string, int> $denominatorSeries
     *
     * @return array<string, int>
     */
    private function buildRatioSeries(array $numeratorSeries, array $denominatorSeries, MetricUnit $unit): array
    {
        $series = [];

        foreach ($numeratorSeries as $date => $numeratorValue) {
            $series[$date] = $this->computeRatio($numeratorValue, $denominatorSeries[$date] ?? 0, $unit);
        }

        return $series;
    }

    private function computeRatio(int $numerator, int $denominator, MetricUnit $unit): int
    {
        if (0 === $denominator) {
            return 0;
        }

        $ratio = $numerator / $denominator;

        return MetricUnit::Percent === $unit ? (int) round($ratio * 100) : (int) round($ratio);
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
