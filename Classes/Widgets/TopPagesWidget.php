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

use KonradMichalik\Typo3PageMetrics\Service\SiteMetricsOverviewBuilder;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\Site\{Entity\Site, SiteFinder};
use TYPO3\CMS\Dashboard\Widgets\{AdditionalCssInterface, WidgetConfigurationInterface, WidgetContext, WidgetRendererInterface, WidgetResult};

/**
 * TopPagesWidget.
 *
 * The highest-traffic pages per site, resolved to their current titles and
 * linked into the page module - unlike a raw Matomo/GA4 URL list, this is
 * only possible because the daily import already resolves URLs to page
 * UIDs. The page-level setting restricts the ranking to pages at an exact
 * tree depth below the site root (e.g. only top-level sections) instead of
 * the whole site; "include subpages" then rolls each of those pages' own
 * subpages into its number instead of leaving them out - see
 * SiteMetricsOverviewBuilder::buildTopPagesForSite().
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TopPagesWidget implements WidgetRendererInterface, AdditionalCssInterface
{
    private const PERIOD_DAYS = 30;

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
                'level',
                'string',
                '',
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.topPages.setting.level.label',
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.topPages.setting.level.description',
                false,
                [
                    '' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.topPages.setting.level.all',
                    '1' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.topPages.setting.level.1',
                    '2' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.topPages.setting.level.2',
                    '3' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.topPages.setting.level.3',
                ],
            ),
            new SettingDefinition(
                'includeSubpages',
                'bool',
                false,
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:subpages.include',
                'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.topPages.setting.includeSubpages.description',
            ),
        ];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        $level = (string) $context->settings->get('level');
        $level = '' !== $level ? (int) $level : null;
        $includeSubpages = (bool) $context->settings->get('includeSubpages');

        $sites = $this->collectTopPages($level, $includeSubpages);

        $view = $this->backendViewFactory->create($context->request, ['konradmichalik/typo3-page-metrics', 'typo3/cms-dashboard']);
        $view->assignMultiple([
            'sites' => $sites,
            'configuration' => $this->configuration,
        ]);

        return new WidgetResult($view->render('Widget/TopPages'), [] !== $sites ? $this->buildLabel($level, $includeSubpages) : null);
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
     * title alone doesn't tell two instances with different level settings
     * apart on the dashboard, so the resolved level is appended to make a
     * user's configuration visible without opening the settings dialog.
     * "Include subpages" is only appended when a level is actually selected:
     * it has no effect otherwise (see buildTopPagesForSite()), so showing it
     * regardless would claim a configuration that isn't really active.
     */
    private function buildLabel(?int $level, bool $includeSubpages): string
    {
        $languageService = $this->getLanguageService();

        $parts = [
            $languageService->sL($this->configuration->getTitle()),
            $languageService->sL('LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:widget.topPages.setting.level.'.($level ?? 'all')),
        ];

        if (null !== $level && $includeSubpages) {
            $parts[] = $languageService->sL('LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:subpages.include');
        }

        return implode(' · ', $parts);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * @return list<array{site: Site, topPages: list<\KonradMichalik\Typo3PageMetrics\Domain\ValueObject\TopPage>}>
     */
    private function collectTopPages(?int $level, bool $includeSubpages): array
    {
        $result = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            $topPages = $this->overviewBuilder->buildTopPagesForSite($site, 0, self::PERIOD_DAYS, $level, $includeSubpages);

            if (null === $topPages) {
                continue;
            }

            $result[] = ['site' => $site, 'topPages' => $topPages];
        }

        return $result;
    }
}
