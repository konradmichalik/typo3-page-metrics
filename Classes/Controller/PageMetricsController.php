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

namespace KonradMichalik\Typo3PageMetrics\Controller;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, MetricTile};
use KonradMichalik\Typo3PageMetrics\Persistence\DailyMetricsRepository;
use KonradMichalik\Typo3PageMetrics\Source\MetricSourceInterface;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

class PageMetricsController
{
    /** @var list<int> */
    private const PERIOD_OPTIONS = [7, 30, 90];
    private const SPARKLINE_DAYS = 30;

    /**
     * @param iterable<MetricSourceInterface> $sources
     */
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SiteFinder $siteFinder,
        private readonly DailyMetricsRepository $dailyMetricsRepository,
        private readonly PageRenderer $pageRenderer,
        private readonly iterable $sources,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $this->pageRenderer->addCssFile('EXT:page_metrics/Resources/Public/Css/page-metrics.css');

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $pageId = (int) ($request->getQueryParams()['id'] ?? 0);
        $languageId = (int) ($request->getQueryParams()['language'] ?? 0);
        $periodDays = $this->resolvePeriodDays($request);
        $site = $this->resolveSite($pageId);

        $moduleTemplate->assignMultiple([
            'pageId' => $pageId,
            'hasPage' => $pageId > 0 && null !== $site,
            'periodDays' => $periodDays,
            'periodOptions' => self::PERIOD_OPTIONS,
            'tiles' => null !== $site ? $this->buildTiles($site, $pageId, $languageId, $periodDays) : [],
        ]);
        $moduleTemplate->setTitle('Page Metrics');

        return $moduleTemplate->renderResponse('PageMetrics/Index');
    }

    private function resolvePeriodDays(ServerRequestInterface $request): int
    {
        $requested = (int) ($request->getQueryParams()['period'] ?? 30);

        return in_array($requested, self::PERIOD_OPTIONS, true) ? $requested : 30;
    }

    /**
     * @return list<MetricTile>
     */
    private function buildTiles(Site $site, int $pageId, int $languageId, int $periodDays): array
    {
        /** @var array<string, MetricDefinition> $definitionsByIdentifier */
        $definitionsByIdentifier = [];

        foreach ($this->sources as $source) {
            if (!$source->supports($site)) {
                continue;
            }

            foreach ($source->getMetricDefinitions() as $definition) {
                $definitionsByIdentifier[$definition->identifier] = $definition;
            }
        }

        $definitions = array_values($definitionsByIdentifier);
        usort($definitions, static fn (MetricDefinition $a, MetricDefinition $b): int => $a->sortOrder <=> $b->sortOrder);

        $today = new \DateTimeImmutable('today');
        $tileRange = new DateRange($today->modify(sprintf('-%d days', $periodDays - 1)), $today);
        $sparklineRange = new DateRange($today->modify(sprintf('-%d days', self::SPARKLINE_DAYS - 1)), $today);

        $tiles = [];

        foreach ($definitions as $definition) {
            $sparkline = $this->dailyMetricsRepository->dailySeries($pageId, $languageId, $definition->identifier, $sparklineRange);
            $tileSeries = $this->dailyMetricsRepository->dailySeries($pageId, $languageId, $definition->identifier, $tileRange);

            $tiles[] = new MetricTile($definition->identifier, $definition->label, $definition->icon, $definition->unit, array_sum($tileSeries), $sparkline);
        }

        return $tiles;
    }

    private function resolveSite(int $pageId): ?Site
    {
        if ($pageId < 1) {
            return null;
        }

        try {
            return $this->siteFinder->getSiteByPageId($pageId);
        } catch (\Throwable) {
            return null;
        }
    }
}
