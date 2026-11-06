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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\TrendSentiment;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DataFreshness;
use KonradMichalik\Typo3PageMetrics\Service\SiteMetricsOverviewBuilder;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\Site\{Entity\Site, SiteFinder};
use TYPO3\CMS\Dashboard\Widgets\{AdditionalCssInterface, WidgetConfigurationInterface, WidgetContext, WidgetRendererInterface, WidgetResult};

use function count;
use function in_array;
use function reset;

/**
 * TrendWidget.
 *
 * The pages with the biggest rise or drop in a metric, comparing a
 * configurable period against the period immediately before it - metric,
 * direction and period are all user-configurable per widget instance, see
 * getSettingsDefinitions().
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TrendWidget implements WidgetRendererInterface, AdditionalCssInterface
{
    private const LIMIT = 5;

    /**
     * @var list<int>
     */
    private const PERIOD_OPTIONS = [7, 30, 90];

    public function __construct(
        private WidgetConfigurationInterface $configuration,
        private SiteMetricsOverviewBuilder $overviewBuilder,
        private SiteFinder $siteFinder,
        private BackendViewFactory $backendViewFactory,
    ) {}

    /**
     * @return list<SettingDefinition>
     */
    public function getSettingsDefinitions(): array
    {
        return [
            new SettingDefinition(
                'metric',
                'string',
                '',
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.metric.label',
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.metric.description',
                false,
                [
                    '' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.chart.setting.metric.auto',
                    ...$this->availableMetrics(),
                ],
            ),
            new SettingDefinition(
                'sentiment',
                'string',
                TrendSentiment::Negative->value,
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.sentiment.label',
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.sentiment.description',
                false,
                [
                    TrendSentiment::Negative->value => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.sentiment.declined',
                    TrendSentiment::Positive->value => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.sentiment.improved',
                ],
            ),
            new SettingDefinition(
                'period',
                'int',
                30,
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.period.label',
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.period.description',
                false,
                [
                    7 => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.period.7',
                    30 => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.period.30',
                    90 => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.period.90',
                ],
            ),
        ];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        $metric = (string) $context->settings->get('metric');
        $sentiment = TrendSentiment::tryFrom((string) $context->settings->get('sentiment')) ?? TrendSentiment::Negative;
        $period = (int) $context->settings->get('period');
        $period = in_array($period, self::PERIOD_OPTIONS, true) ? $period : 30;

        $sites = $this->collectTrendPages($metric, $sentiment, $period);

        $view = $this->backendViewFactory->create($context->request, ['konradmichalik/typo3-page-metrics', 'typo3/cms-dashboard']);
        $view->assignMultiple([
            'sites' => $sites,
            'dataFreshness' => DataFreshness::oldestOf(array_column($sites, 'dataFreshness')),
            'sentiment' => $this->rowSentiment($sites)->value,
            'columnLabel' => '' !== $metric ? ($this->availableMetrics()[$metric] ?? null) : null,
            'configuration' => $this->configuration,
        ]);

        return new WidgetResult($view->render('Widget/Trend'), [] !== $sites ? $this->buildLabel($metric, $sentiment, $period) : null);
    }

    /**
     * @return list<string>
     */
    public function getCssFiles(): array
    {
        return ['EXT:typo3_page_metrics/Resources/Public/Css/page-metrics.css'];
    }

    /**
     * The colour of the widget's one aside icon, taken from the rows it
     * actually rendered rather than from the setting that selected them: a
     * metric without polarity (an average time on page) ranks by direction
     * but every one of its rows is TrendSentiment::Neutral, and a
     * multi-site widget can resolve a different metric per site. Anything
     * but one unanimous sentiment stays neutral rather than colouring the
     * icon against its own rows.
     *
     * @param list<array{site: Site, topPages: list<\KonradMichalik\Typo3PageMetrics\Domain\ValueObject\TopPage>}> $sites
     */
    private function rowSentiment(array $sites): TrendSentiment
    {
        $sentiments = [];

        foreach ($sites as $site) {
            foreach ($site['topPages'] as $topPage) {
                $sentiments[$topPage->sentiment->value] = $topPage->sentiment;
            }
        }

        return 1 === count($sentiments) ? reset($sentiments) : TrendSentiment::Neutral;
    }

    /**
     * Mirrors PageMetricsChartWidget::buildLabel(): the registered widget
     * title alone doesn't tell two trend widgets with different settings
     * apart on the dashboard, so the resolved metric, direction and period
     * are appended to make a user's configuration visible without opening
     * the settings dialog. Unlike the chart widget, the metric segment is
     * built from the raw setting value rather than a per-site resolved
     * label: direction and period are already setting-based here, and a
     * per-site fallback (a configured metric one site's provider doesn't
     * support) is a rarer edge case for a ranking than for a single chart
     * line.
     */
    private function buildLabel(string $metricIdentifier, TrendSentiment $sentiment, int $period): string
    {
        $languageService = $this->getLanguageService();
        $sentimentKey = TrendSentiment::Positive === $sentiment
            ? 'widget.trend.setting.sentiment.improved'
            : 'widget.trend.setting.sentiment.declined';

        $parts = [$languageService->sL($this->configuration->getTitle())];

        if ('' !== $metricIdentifier) {
            $metricLabel = $this->availableMetrics()[$metricIdentifier] ?? null;

            if (null !== $metricLabel) {
                $parts[] = $metricLabel;
            }
        }

        $parts[] = $languageService->sL('LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:'.$sentimentKey);
        $parts[] = $languageService->sL('LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.period.'.$period);

        return implode(' · ', $parts);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * Without the synthetic "Bot traffic" summary: the ranking sums stored
     * rows per page, and nothing is ever stored under its identifier.
     *
     * @return array<string, string>
     */
    private function availableMetrics(): array
    {
        return $this->overviewBuilder->availableMetrics($this->siteFinder->getAllSites(), false);
    }

    /**
     * @return list<array{site: Site, topPages: list<\KonradMichalik\Typo3PageMetrics\Domain\ValueObject\TopPage>, dataFreshness: ?DataFreshness}>
     */
    private function collectTrendPages(string $metricIdentifier, TrendSentiment $sentiment, int $period): array
    {
        $preferredMetricIdentifier = '' !== $metricIdentifier ? $metricIdentifier : null;
        $result = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            $topPages = $this->overviewBuilder->buildTrendPages($site, 0, $period, $sentiment, $preferredMetricIdentifier, self::LIMIT);

            if (null === $topPages) {
                continue;
            }

            $result[] = ['site' => $site, 'topPages' => $topPages, 'dataFreshness' => $this->overviewBuilder->dataFreshnessFor($site)];
        }

        return $result;
    }
}
