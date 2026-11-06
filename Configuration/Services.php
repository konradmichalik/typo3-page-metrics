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

namespace KonradMichalik\Typo3PageMetrics;

use KonradMichalik\Typo3PageMetrics\Service\{AnnotationPayloadBuilder, DonutChartBuilder, InsightBuilder, PageMetricsViewDataBuilder, SiteMetricsOverviewBuilder, SparklineLabelProvider, TileStatBuilder};
use KonradMichalik\Typo3PageMetrics\Widgets\{AnnotationsWidget, InsightWidget, NotFoundPagesWidget, PageMetricsBreakdownWidget, PageMetricsChartWidget, PageMetricsWidget, TopPagesWidget, TrendWidget};
use Symfony\Component\DependencyInjection\{ContainerBuilder, Reference};
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Dashboard\WidgetRegistry;
use TYPO3\CMS\Dashboard\Widgets\WidgetRendererInterface;

use function interface_exists;

/*
 * Registration directly in Services.yaml would autowire PageMetricsWidget
 * unconditionally, even on installations without EXT:dashboard - the
 * WidgetRegistry class only exists once that (optional) extension is
 * loaded, so its presence in the container is what we check here.
 */
return static function (ContainerConfigurator $configurator, ContainerBuilder $containerBuilder): void {
    if (!$containerBuilder->hasDefinition(WidgetRegistry::class)) {
        return;
    }

    // WidgetRendererInterface - needed by PageMetricsWidget,
    // PageMetricsChartWidget, TopPagesWidget and TrendWidget for their
    // settings UI (period/metric/direction) - is a TYPO3 14 addition,
    // absent from TYPO3 13.4's cms-dashboard. Guarding just those four
    // keeps the rest of the dashboard integration (not-found pages,
    // annotations - still on the older WidgetInterface via
    // AbstractSiteMetricsWidget) working on both supported TYPO3 versions;
    // a preset referencing one of the four on TYPO3 13 just omits that
    // tile, see Dashboard::initializeWidgets() in EXT:dashboard.
    $supportsSettingsAwareWidgets = interface_exists(WidgetRendererInterface::class);

    $services = $configurator->services();

    // Nothing here is autowired, so each widget names its arguments.
    // First in the preset and first here: it implements the plain widget
    // interface with no settings, so unlike most of the ones below it needs
    // no version gate at all.
    $services->set('dashboard.widget.pageMetricsInsight')
        ->class(InsightWidget::class)
        ->arg('$insightBuilder', new Reference(InsightBuilder::class))
        ->arg('$siteFinder', new Reference(SiteFinder::class))
        ->arg('$backendViewFactory', new Reference(BackendViewFactory::class))
        ->tag('dashboard.widget', [
            'identifier' => 'pageMetricsInsight',
            'groupNames' => 'pageMetrics',
            'title' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.insight.title',
            'description' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.insight.description',
            'iconIdentifier' => 'tabler-speakerphone',
            'height' => 'medium',
            'width' => 'medium',
        ]);

    if ($supportsSettingsAwareWidgets) {
        $services->set('dashboard.widget.pageMetrics')
            ->class(PageMetricsWidget::class)
            ->arg('$viewDataBuilder', new Reference(PageMetricsViewDataBuilder::class))
            ->arg('$siteFinder', new Reference(SiteFinder::class))
            ->arg('$backendViewFactory', new Reference(BackendViewFactory::class))
            ->arg('$labelProvider', new Reference(SparklineLabelProvider::class))
            ->arg('$annotationPayloadBuilder', new Reference(AnnotationPayloadBuilder::class))
            ->tag('dashboard.widget', [
                'identifier' => 'pageMetrics',
                'groupNames' => 'pageMetrics',
                'title' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.title',
                'description' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.description',
                'iconIdentifier' => 'tabler-eye-search',
                'height' => 'small',
                'width' => 'large',
            ]);

        $services->set('dashboard.widget.pageMetricsBreakdown')
            ->class(PageMetricsBreakdownWidget::class)
            ->arg('$viewDataBuilder', new Reference(PageMetricsViewDataBuilder::class))
            ->arg('$overviewBuilder', new Reference(SiteMetricsOverviewBuilder::class))
            ->arg('$statBuilder', new Reference(TileStatBuilder::class))
            ->arg('$donutBuilder', new Reference(DonutChartBuilder::class))
            ->arg('$labelProvider', new Reference(SparklineLabelProvider::class))
            ->arg('$siteFinder', new Reference(SiteFinder::class))
            ->arg('$backendViewFactory', new Reference(BackendViewFactory::class))
            ->tag('dashboard.widget', [
                'identifier' => 'pageMetricsBreakdown',
                'groupNames' => 'pageMetrics',
                'title' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.breakdown.title',
                'description' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.breakdown.description',
                'iconIdentifier' => 'tabler-list-details',
                'height' => 'medium',
                'width' => 'medium',
            ]);
    }

    if ($supportsSettingsAwareWidgets) {
        $services->set('dashboard.widget.pageMetricsChart')
            ->class(PageMetricsChartWidget::class)
            ->arg('$overviewBuilder', new Reference(SiteMetricsOverviewBuilder::class))
            ->arg('$siteFinder', new Reference(SiteFinder::class))
            ->arg('$backendViewFactory', new Reference(BackendViewFactory::class))
            ->arg('$labelProvider', new Reference(SparklineLabelProvider::class))
            ->arg('$annotationPayloadBuilder', new Reference(AnnotationPayloadBuilder::class))
            ->tag('dashboard.widget', [
                'identifier' => 'pageMetricsChart',
                'groupNames' => 'pageMetrics',
                'title' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.chart.title',
                'description' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.chart.description',
                'iconIdentifier' => 'tabler-chart-dots',
                'height' => 'medium',
                'width' => 'large',
            ]);

        $services->set('dashboard.widget.pageMetricsChartCompact')
            ->class(PageMetricsChartWidget::class)
            ->arg('$overviewBuilder', new Reference(SiteMetricsOverviewBuilder::class))
            ->arg('$siteFinder', new Reference(SiteFinder::class))
            ->arg('$backendViewFactory', new Reference(BackendViewFactory::class))
            ->arg('$labelProvider', new Reference(SparklineLabelProvider::class))
            ->arg('$annotationPayloadBuilder', new Reference(AnnotationPayloadBuilder::class))
            ->tag('dashboard.widget', [
                'identifier' => 'pageMetricsChartCompact',
                'groupNames' => 'pageMetrics',
                'title' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.chartCompact.title',
                'description' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.chartCompact.description',
                'iconIdentifier' => 'tabler-chart-dots',
                'height' => 'small',
                'width' => 'medium',
            ]);
    }

    if ($supportsSettingsAwareWidgets) {
        $services->set('dashboard.widget.pageMetricsTopPages')
            ->class(TopPagesWidget::class)
            ->arg('$overviewBuilder', new Reference(SiteMetricsOverviewBuilder::class))
            ->arg('$siteFinder', new Reference(SiteFinder::class))
            ->arg('$backendViewFactory', new Reference(BackendViewFactory::class))
            ->tag('dashboard.widget', [
                'identifier' => 'pageMetricsTopPages',
                'groupNames' => 'pageMetrics',
                'title' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.topPages.title',
                'description' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.topPages.description',
                'iconIdentifier' => 'tabler-list',
                'height' => 'medium',
                'width' => 'medium',
            ]);
    }

    $services->set('dashboard.widget.pageMetricsNotFoundPages')
        ->class(NotFoundPagesWidget::class)
        ->arg('$overviewBuilder', new Reference(SiteMetricsOverviewBuilder::class))
        ->arg('$siteFinder', new Reference(SiteFinder::class))
        ->arg('$backendViewFactory', new Reference(BackendViewFactory::class))
        ->tag('dashboard.widget', [
            'identifier' => 'pageMetricsNotFoundPages',
            'groupNames' => 'pageMetrics',
            'title' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.notFoundPages.title',
            'description' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.notFoundPages.description',
            'iconIdentifier' => 'tabler-world-search',
            'height' => 'medium',
            'width' => 'medium',
        ]);

    if ($supportsSettingsAwareWidgets) {
        $services->set('dashboard.widget.pageMetricsTrend')
            ->class(TrendWidget::class)
            ->arg('$overviewBuilder', new Reference(SiteMetricsOverviewBuilder::class))
            ->arg('$siteFinder', new Reference(SiteFinder::class))
            ->arg('$backendViewFactory', new Reference(BackendViewFactory::class))
            ->tag('dashboard.widget', [
                'identifier' => 'pageMetricsTrend',
                'groupNames' => 'pageMetrics',
                'title' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.title',
                'description' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.description',
                'iconIdentifier' => 'tabler-trending-down',
                'height' => 'medium',
                'width' => 'medium',
            ]);
    }

    $services->set('dashboard.widget.pageMetricsAnnotations')
        ->class(AnnotationsWidget::class)
        ->arg('$overviewBuilder', new Reference(SiteMetricsOverviewBuilder::class))
        ->arg('$siteFinder', new Reference(SiteFinder::class))
        ->arg('$backendViewFactory', new Reference(BackendViewFactory::class))
        ->tag('dashboard.widget', [
            'identifier' => 'pageMetricsAnnotations',
            'groupNames' => 'pageMetrics',
            'title' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.annotations.title',
            'description' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.annotations.description',
            'iconIdentifier' => 'tabler-note',
            'height' => 'medium',
            'width' => 'small',
        ]);
};
