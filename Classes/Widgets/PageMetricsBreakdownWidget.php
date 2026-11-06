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

namespace KonradMichalik\Typo3PageMetrics\Widgets;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DataFreshness, DonutChart, MetricTile, TileStats};
use KonradMichalik\Typo3PageMetrics\Service\{DonutChartBuilder, PageMetricsViewDataBuilder, SiteMetricsOverviewBuilder, SparklineLabelProvider, TileStatBuilder};
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Dashboard\Widgets\{AdditionalCssInterface, JavaScriptInterface, WidgetConfigurationInterface, WidgetContext, WidgetRendererInterface, WidgetResult};

use function array_keys;
use function array_map;
use function count;

/**
 * PageMetricsBreakdownWidget.
 *
 * One configured metric (defaults to each site's primary metric, the same
 * fallback PageMetricsChartWidget uses), read from the same per-site
 * homepage tiles PageMetricsWidget shows (same PageMetricsViewDataBuilder
 * call, same period setting) - not every tile at once, but one tile's figures
 * spelled out: last day, previous day, peak and average (see
 * TileStatBuilder, which computes what the tile's own popover would compute
 * client-side, for a widget with no hover of its own to open one), and its
 * breakdown into parts where it has one (e.g. Bot traffic split by crawler,
 * see TileBreakdownBuilder), drawn as a donut where DonutChartBuilder can
 * size one (falls back to the plain list otherwise), and the same per-day
 * curve the tile's own popover charts - drawn by sparkline.js's
 * initializeStaticCharts(), the same code PageMetricsChartWidget uses, fed a
 * single-series array built from this one tile's own sparkline rather than a
 * whole SiteMetricsOverview.
 *
 * Implements WidgetRendererInterface rather than the simpler WidgetInterface
 * for the same reason PageMetricsWidget does: only that TYPO3 14 addition
 * gets a settings UI, see getSettingsDefinitions() and
 * Configuration/Services.php.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class PageMetricsBreakdownWidget implements WidgetRendererInterface, JavaScriptInterface, AdditionalCssInterface
{
    private const DEFAULT_PERIOD_DAYS = 7;

    public function __construct(
        private WidgetConfigurationInterface $configuration,
        private PageMetricsViewDataBuilder $viewDataBuilder,
        private SiteMetricsOverviewBuilder $overviewBuilder,
        private TileStatBuilder $statBuilder,
        private DonutChartBuilder $donutBuilder,
        private SparklineLabelProvider $labelProvider,
        private SiteFinder $siteFinder,
        private BackendViewFactory $backendViewFactory,
    ) {}

    /**
     * @return list<SettingDefinition>
     */
    public function getSettingsDefinitions(): array
    {
        // Every other machine metric is folded into the summary tile, so
        // findTile() would never find it.
        $availableMetrics = $this->overviewBuilder->availableMetrics(
            $this->siteFinder->getAllSites(),
            true,
            array_keys(PageMetricsViewDataBuilder::AGENT_DETAIL_METRICS),
        );

        return [
            new SettingDefinition(
                'metric',
                'string',
                '',
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.breakdown.setting.metric.label',
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.breakdown.setting.metric.description',
                false,
                [
                    '' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.chart.setting.metric.auto',
                    ...$availableMetrics,
                ],
            ),
            WidgetPeriod::settingDefinition(self::DEFAULT_PERIOD_DAYS, 'widget.setting.period.description'),
        ];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        $metric = (string) $context->settings->get('metric');
        $period = (int) $context->settings->get('period');
        $period = WidgetPeriod::resolve($period, self::DEFAULT_PERIOD_DAYS);

        $sites = $this->collectSiteTiles($metric, $period);

        // 'typo3/cms-dashboard' as a second package lets the template use
        // <f:layout name="Widget/Widget" /> from EXT:dashboard itself, the
        // same layout every core widget renders through.
        $view = $this->backendViewFactory->create($context->request, ['konradmichalik/typo3-page-metrics', 'typo3/cms-dashboard']);
        $view->assignMultiple([
            'sites' => $sites,
            'sparklineLabels' => $this->labelProvider->all(),
            // One mark for the whole card, the same way TrendWidget and
            // PageMetricsChartWidget aggregate it: a card is only as current
            // as its stalest site.
            'dataFreshness' => DataFreshness::oldestOf(array_map(static fn (array $siteTile): ?DataFreshness => $siteTile['dataFreshness'], $sites)),
            'configuration' => $this->configuration,
        ]);

        return new WidgetResult($view->render('Widget/PageMetricsBreakdown'), [] !== $sites ? $this->buildLabel($period, $sites) : null);
    }

    /**
     * @return list<JavaScriptModuleInstruction>
     */
    public function getJavaScriptModuleInstructions(): array
    {
        return [JavaScriptModuleInstruction::create('@konradmichalik/page-metrics/sparkline.js')];
    }

    /**
     * @return list<string>
     */
    public function getCssFiles(): array
    {
        return ['EXT:typo3_page_metrics/Resources/Public/Css/page-metrics.css'];
    }

    /**
     * Same rule as PageMetricsChartWidget::buildLabel(): a single site names
     * its resolved metric here, since the content then has no h3 carrying it.
     *
     * @param non-empty-list<array{tile: MetricTile}> $sites
     */
    private function buildLabel(int $period, array $sites): string
    {
        $languageService = $this->getLanguageService();
        $parts = [
            $languageService->sL($this->configuration->getTitle()),
            $languageService->sL(WidgetPeriod::labelKey($period)),
        ];

        if (1 === count($sites)) {
            $parts[] = $sites[0]['tile']->label;
        }

        return implode(' · ', $parts);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * @return list<array{site: Site, tile: MetricTile, stats: TileStats, donut: ?DonutChart, series: list<array{label: string, color: string, unit: string, values: array<string, int|null>}>, dataFreshness: ?DataFreshness}>
     */
    private function collectSiteTiles(string $preferredMetricIdentifier, int $period): array
    {
        $result = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            $viewData = $this->viewDataBuilder->build($site->getRootPageId(), 0, $period, false, false);

            if (!$viewData->hasPage || [] === $viewData->tiles) {
                continue;
            }

            // An empty setting, or one this site's own providers don't
            // support, falls back to that site's primary metric - the tile
            // PageMetricsViewDataBuilder always places first - the same
            // "unsupported means primary" rule PageMetricsChartWidget
            // applies through SiteMetricsOverviewBuilder::build().
            $tile = $this->findTile($viewData->tiles, $preferredMetricIdentifier) ?? $viewData->tiles[0];

            $result[] = [
                'site' => $site,
                'tile' => $tile,
                'stats' => $this->statBuilder->build($tile),
                'donut' => $this->donutBuilder->build($tile->breakdown),
                // The same single-series shape PageMetricsChartWidget builds
                // from a whole SiteMetricsOverview (see its buildViewVariables()),
                // here fed straight from this one tile's own sparkline -
                // sparkline.js's chart drawer takes either.
                'series' => [
                    ['label' => $tile->label, 'color' => $tile->color, 'unit' => $tile->unit->value, 'values' => $tile->sparkline],
                ],
                'dataFreshness' => $viewData->dataFreshness,
            ];
        }

        return $result;
    }

    /**
     * @param list<MetricTile> $tiles
     */
    private function findTile(array $tiles, string $identifier): ?MetricTile
    {
        if ('' === $identifier) {
            return null;
        }

        foreach ($tiles as $tile) {
            if ($tile->identifier === $identifier) {
                return $tile;
            }
        }

        return null;
    }
}
