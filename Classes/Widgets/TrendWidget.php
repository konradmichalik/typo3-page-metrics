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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\TrendDirection;
use KonradMichalik\Typo3PageMetrics\Service\SiteMetricsOverviewBuilder;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\Site\{Entity\Site, SiteFinder};
use TYPO3\CMS\Dashboard\Widgets\{AdditionalCssInterface, WidgetConfigurationInterface, WidgetContext, WidgetRendererInterface, WidgetResult};

use function in_array;

/**
 * TrendWidget.
 *
 * The pages with the biggest rise or drop in the site's primary metric,
 * comparing a configurable period against the period immediately before it
 * - direction and period are both user-configurable per widget instance,
 * see getSettingsDefinitions().
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
                'direction',
                'string',
                TrendDirection::Down->value,
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.direction.label',
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.direction.description',
                false,
                [
                    TrendDirection::Down->value => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.direction.down',
                    TrendDirection::Up->value => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.direction.up',
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
        $direction = TrendDirection::tryFrom((string) $context->settings->get('direction')) ?? TrendDirection::Down;
        $period = (int) $context->settings->get('period');
        $period = in_array($period, self::PERIOD_OPTIONS, true) ? $period : 30;

        $sites = $this->collectTrendPages($direction, $period);

        $view = $this->backendViewFactory->create($context->request, ['konradmichalik/typo3-page-metrics', 'typo3/cms-dashboard']);
        $view->assignMultiple([
            'sites' => $sites,
            'direction' => $direction->value,
            'configuration' => $this->configuration,
        ]);

        return new WidgetResult($view->render('Widget/Trend'), [] !== $sites ? $this->buildLabel($direction, $period) : null);
    }

    /**
     * @return list<string>
     */
    public function getCssFiles(): array
    {
        return ['EXT:typo3_page_metrics/Resources/Public/Css/page-metrics.css'];
    }

    /**
     * Mirrors PageMetricsChartWidget::buildLabel(): the registered widget
     * title alone doesn't tell two trend widgets with different settings
     * apart on the dashboard, so the resolved direction and period are
     * appended to make a user's configuration visible without opening the
     * settings dialog.
     */
    private function buildLabel(TrendDirection $direction, int $period): string
    {
        $languageService = $this->getLanguageService();
        $directionKey = TrendDirection::Up === $direction
            ? 'widget.trend.setting.direction.up'
            : 'widget.trend.setting.direction.down';

        return implode(' · ', [
            $languageService->sL($this->configuration->getTitle()),
            $languageService->sL('LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:'.$directionKey),
            $languageService->sL('LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.trend.setting.period.'.$period),
        ]);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * @return list<array{site: Site, topPages: list<\KonradMichalik\Typo3PageMetrics\Domain\ValueObject\TopPage>}>
     */
    private function collectTrendPages(TrendDirection $direction, int $period): array
    {
        $result = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            $topPages = $this->overviewBuilder->buildTrendPages($site, 0, $period, $direction, self::LIMIT);

            if (null === $topPages) {
                continue;
            }

            $result[] = ['site' => $site, 'topPages' => $topPages];
        }

        return $result;
    }
}
