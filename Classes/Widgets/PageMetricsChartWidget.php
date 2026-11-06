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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DataFreshness, SiteMetricsOverview};
use KonradMichalik\Typo3PageMetrics\Service\{AnnotationPayloadBuilder, SiteMetricsOverviewBuilder, SparklineLabelProvider};
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Dashboard\Widgets\{AdditionalCssInterface, JavaScriptInterface, WidgetConfigurationInterface, WidgetContext, WidgetRendererInterface, WidgetResult};

use function count;
use function in_array;

/**
 * PageMetricsChartWidget.
 *
 * A site-wide daily chart of a configurable metric (defaults to each site's
 * primary metric), with the same dashed annotation markers the PageHeader
 * sparkline draws - reuses sparkline.js's own SVG-drawing code (see
 * initializeStaticCharts() there), just rendered inline instead of behind a
 * popover.
 *
 * Implements WidgetRendererInterface rather than the simpler WidgetInterface
 * (see AbstractSiteMetricsWidget, used by the other three site-metrics
 * widgets) because only WidgetRendererInterface widgets get a settings UI -
 * see getSettingsDefinitions().
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PageMetricsChartWidget implements WidgetRendererInterface, JavaScriptInterface, AdditionalCssInterface
{
    /**
     * @var list<int>
     */
    private const PERIOD_OPTIONS = [7, 30, 90];

    public function __construct(
        private WidgetConfigurationInterface $configuration,
        private SiteMetricsOverviewBuilder $overviewBuilder,
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
        $availableMetrics = $this->overviewBuilder->availableMetrics($this->siteFinder->getAllSites());

        return [
            new SettingDefinition(
                'metric',
                'string',
                '',
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.chart.setting.metric.label',
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.chart.setting.metric.description',
                false,
                [
                    '' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.chart.setting.metric.auto',
                    ...$availableMetrics,
                ],
            ),
            new SettingDefinition(
                'period',
                'int',
                30,
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.chart.setting.period.label',
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.chart.setting.period.description',
                false,
                [
                    7 => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.period.7',
                    30 => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.period.30',
                    90 => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.period.90',
                ],
            ),
            new SettingDefinition(
                'annotationCategory',
                'string',
                '',
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.chart.setting.annotationCategory.label',
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.chart.setting.annotationCategory.description',
                false,
                [
                    '' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.chart.setting.annotationCategory.all',
                    ...$this->annotationCategoryOptions(),
                ],
            ),
        ];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        $metric = (string) $context->settings->get('metric');
        $period = (int) $context->settings->get('period');
        $period = in_array($period, self::PERIOD_OPTIONS, true) ? $period : 30;
        // An empty setting means every kind, which is what a chart showed
        // before markers had kinds at all.
        $annotationCategory = AnnotationCategory::tryFrom((string) $context->settings->get('annotationCategory'));
        $overviews = $this->collectOverviews($metric, $period, $annotationCategory);

        $view = $this->backendViewFactory->create($context->request, ['konradmichalik/typo3-page-metrics', 'typo3/cms-dashboard']);
        $view->assignMultiple([
            ...$this->buildViewVariables($overviews),
            'sparklineLabels' => $this->labelProvider->all(),
            // One mark for the whole card, the same way TrendWidget aggregates
            // it: a card is only as current as its stalest site.
            'dataFreshness' => DataFreshness::oldestOf(array_map(static fn (SiteMetricsOverview $overview): ?DataFreshness => $overview->dataFreshness, $overviews)),
            'configuration' => $this->configuration,
        ]);

        return new WidgetResult($view->render('Widget/Chart'), [] !== $overviews ? $this->buildLabel($period, $overviews) : null);
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
     * @return array<string, string>
     */
    private function annotationCategoryOptions(): array
    {
        $options = [];

        foreach (AnnotationCategory::cases() as $category) {
            $options[$category->value] = $category->label();
        }

        return $options;
    }

    /**
     * The registered widget title alone doesn't tell two chart widgets with
     * different settings apart on the dashboard - appending the resolved
     * period, and (for a single-site widget) that site's resolved metric
     * name, makes the settings a user picked visible without opening the
     * settings dialog. Reads the metric name off the overview itself rather
     * than the raw "metric" setting: a configured metric the site's provider
     * doesn't support falls back to the primary metric (see build()), and the
     * label needs to reflect what's actually plotted, not what was asked
     * for. A multi-site widget keeps its per-site labels in the content
     * instead, since different sites can resolve to different metrics.
     *
     * @param list<SiteMetricsOverview> $overviews
     */
    private function buildLabel(int $period, array $overviews): string
    {
        $languageService = $this->getLanguageService();
        $parts = [
            $languageService->sL($this->configuration->getTitle()),
            $languageService->sL('LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.period.'.$period),
        ];

        if (1 === count($overviews)) {
            $parts[] = $overviews[0]->chartLabel;
        }

        return implode(' · ', $parts);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * @return list<SiteMetricsOverview>
     */
    private function collectOverviews(string $preferredMetricIdentifier, int $periodDays, ?AnnotationCategory $annotationCategory): array
    {
        $result = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            $overview = $this->overviewBuilder->build($site, 0, $periodDays, $preferredMetricIdentifier, $annotationCategory);

            if (!$overview->hasProvider) {
                continue;
            }

            $result[] = $overview;
        }

        return $result;
    }

    /**
     * The chart's annotation markers are drawn by JS from a JSON data
     * attribute (see sparkline.js's initializeStaticCharts()), which is why
     * the annotations go through AnnotationPayloadBuilder rather than
     * straight into the view - the same shape the PageHeader tiles and the
     * tiles widget put in their own data-annotations attribute.
     *
     * @param list<SiteMetricsOverview> $overviews
     *
     * @return array<string, mixed>
     */
    private function buildViewVariables(array $overviews): array
    {
        // Captured rather than reached through $this: the closure below is
        // static, like every other mapping closure in this class.
        $payloadBuilder = $this->annotationPayloadBuilder;

        return ['sites' => array_map(
            static fn (SiteMetricsOverview $overview): array => [
                'site' => $overview->site,
                'dataFreshness' => $overview->dataFreshness,
                'chartLabel' => $overview->chartLabel,
                'chartIcon' => $overview->chartIcon,
                'chartColor' => $overview->chartColor,
                'chartUnit' => $overview->chartUnit,
                'annotations' => $payloadBuilder->build($overview->annotations),
                'series' => [
                    ['label' => $overview->chartLabel, 'color' => $overview->chartColor, 'unit' => $overview->chartUnit->value, 'values' => $overview->chartSeries],
                ],
            ],
            $overviews,
        )];
    }
}
