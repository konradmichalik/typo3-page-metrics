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

use KonradMichalik\Typo3PageMetrics\Service\{AnnotationPayloadBuilder, PageMetricsViewDataBuilder, SparklineLabelProvider};
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Dashboard\Widgets\{AdditionalCssInterface, JavaScriptInterface, WidgetConfigurationInterface, WidgetContext, WidgetRendererInterface, WidgetResult};

/**
 * PageMetricsWidget.
 *
 * A dashboard-level presentation of the same PageMetricsViewData the
 * PageHeader tiles and the tiles AJAX controller already render - one row
 * per site, showing that site's homepage tiles for a configurable period.
 * Only registered as a dashboard widget when EXT:dashboard is installed,
 * see Configuration/Services.php.
 *
 * Implements WidgetRendererInterface rather than the simpler WidgetInterface
 * (see AbstractSiteMetricsWidget, used by the other two site-metrics
 * widgets) because only WidgetRendererInterface widgets get a settings UI -
 * see getSettingsDefinitions(). That interface is a TYPO3 14 addition, so
 * this widget, like PageMetricsChartWidget, TopPagesWidget and TrendWidget,
 * is not registered at all on TYPO3 13.4.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class PageMetricsWidget implements WidgetRendererInterface, AdditionalCssInterface, JavaScriptInterface
{
    private const DEFAULT_PERIOD_DAYS = 7;

    public function __construct(
        private WidgetConfigurationInterface $configuration,
        private PageMetricsViewDataBuilder $viewDataBuilder,
        private SiteFinder $siteFinder,
        private BackendViewFactory $backendViewFactory,
        private SparklineLabelProvider $labelProvider,
        private AnnotationPayloadBuilder $annotationPayloadBuilder,
    ) {}

    /**
     * @return list<SettingDefinition>
     */
    public function getSettingsDefinitions(): array
    {
        return [
            WidgetPeriod::settingDefinition(self::DEFAULT_PERIOD_DAYS, 'widget.setting.period.description'),
        ];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        $period = (int) $context->settings->get('period');
        $period = WidgetPeriod::resolve($period, self::DEFAULT_PERIOD_DAYS);

        $sites = $this->collectSiteTiles($period);

        // 'typo3/cms-dashboard' as a second package lets the template use
        // <f:layout name="Widget/Widget" /> from EXT:dashboard itself, the
        // same layout every core widget renders through.
        $view = $this->backendViewFactory->create($context->request, ['konradmichalik/typo3-page-metrics', 'typo3/cms-dashboard']);
        $view->assignMultiple([
            'sites' => $sites,
            'sparklineLabels' => $this->labelProvider->all(),
            'configuration' => $this->configuration,
        ]);

        return new WidgetResult($view->render('Widget/PageMetrics'), [] !== $sites ? $this->buildLabel($period) : null);
    }

    /**
     * @return list<string>
     */
    public function getCssFiles(): array
    {
        return ['EXT:typo3_page_metrics/Resources/Public/Css/page-metrics.css'];
    }

    /**
     * @return list<JavaScriptModuleInstruction>
     */
    public function getJavaScriptModuleInstructions(): array
    {
        return [JavaScriptModuleInstruction::create('@konradmichalik/page-metrics/sparkline.js')];
    }

    /**
     * Mirrors PageMetricsChartWidget::buildLabel(): the registered widget
     * title alone doesn't tell two instances of this widget with different
     * periods apart on the dashboard.
     */
    private function buildLabel(int $period): string
    {
        $languageService = $this->getLanguageService();

        return implode(' · ', [
            $languageService->sL($this->configuration->getTitle()),
            $languageService->sL(WidgetPeriod::labelKey($period)),
        ]);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * @return list<array{site: Site, tiles: list<\KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricTile>, annotations: list<array{date: string, dateUntil: ?string, category: string, text: string}>, dataFreshness: ?\KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DataFreshness}>
     */
    private function collectSiteTiles(int $period): array
    {
        $result = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            $viewData = $this->viewDataBuilder->build($site->getRootPageId(), 0, $period, false, false);

            if (!$viewData->hasPage || [] === $viewData->tiles) {
                continue;
            }

            $result[] = [
                'site' => $site,
                'tiles' => $viewData->tiles,
                'annotations' => $this->annotationPayloadBuilder->build($viewData->annotations),
                'dataFreshness' => $viewData->dataFreshness,
            ];
        }

        return $result;
    }
}
