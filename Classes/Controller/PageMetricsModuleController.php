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

namespace KonradMichalik\Typo3PageMetrics\Controller;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\ModuleView;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\PageMetricsViewData;
use KonradMichalik\Typo3PageMetrics\Provider\{MetricDefinitionCollector, MetricProviderInterface};
use KonradMichalik\Typo3PageMetrics\Service\{AnnotationPayloadBuilder, PageMetricsAccessChecker, PageMetricsViewDataBuilder, PeriodComparisonBuilder, SitePagesTableBuilder, SparklineLabelProvider};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\Action\ShortcutButton;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownRadio;
use TYPO3\CMS\Backend\Template\Components\Buttons\{DropDownButton, LinkButton};
use TYPO3\CMS\Backend\Template\Components\Menu\{Menu, MenuItem};
use TYPO3\CMS\Backend\Template\{ModuleTemplate, ModuleTemplateFactory};
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\{IconFactory, IconSize};
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function in_array;
use function is_array;
use function is_string;

/**
 * PageMetricsModuleController.
 *
 * The frame the module's views render into: a page chosen in the page tree, a
 * period, and which view is active. It owns no data of its own; every view
 * reads through the same services the page-header tiles and the dashboard
 * widgets already use (see PageMetricsViewDataBuilder), which is what made
 * coming back to a module cheap.
 *
 * State lives in `moduleData`, the mechanism the info module uses for its own
 * `depth` and `lang`, and identical in 13.4 and 14. PageMetricsUserPreferences
 * stays out of it: that one holds a decision about the page module's tile
 * row, which is a different surface and must not follow a reader in here.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PageMetricsModuleController
{
    /**
     * The same three the page-header tiles offer. Named here rather than read
     * from PageMetricsViewDataBuilder because the docheader has to render the
     * choice before any view has been asked for data.
     *
     * @var list<int>
     */
    private const PERIOD_OPTIONS = [7, 30, 90];

    private const DEFAULT_PERIOD = 30;

    /**
     * @param iterable<MetricProviderInterface> $providers
     */
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private PageMetricsAccessChecker $accessChecker,
        private UriBuilder $uriBuilder,
        private Typo3Version $typo3Version,
        private PageMetricsViewDataBuilder $viewDataBuilder,
        private SparklineLabelProvider $labelProvider,
        private AnnotationPayloadBuilder $annotationPayloadBuilder,
        private PageRenderer $pageRenderer,
        private PeriodComparisonBuilder $periodComparisonBuilder,
        private SitePagesTableBuilder $sitePagesTableBuilder,
        private IconFactory $iconFactory,
        private MetricDefinitionCollector $metricDefinitionCollector,
        private iterable $providers,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->translate('module.mlang_tabs_tab'));

        if (!$this->accessChecker->hasAccess()) {
            // The same switch that hides the tiles, honoured on the route and
            // not only in the menu: a module path is reachable by typing it.
            // TYPO3 13.4 has no per-user hook for the module menu itself, so
            // the menu entry is hidden with options.hideModules (see
            // docs/security.md); this is the half that cannot be bypassed.
            return $view->assign('reason', 'disabled')->renderResponse('Module/Denied');
        }

        $pageId = (int) ($request->getQueryParams()['id'] ?? 0);
        $moduleData = $this->moduleData($request);
        $period = $this->period($moduleData);
        $activeView = $this->view($moduleData);

        // Every docheader control is a plain link back into this route with
        // the value in the query string. BackendModuleValidator merges a
        // query parameter named like a moduleData key into the stored data
        // and persists it before the request reaches here, so switching the
        // period is one navigation and nothing in this class writes state.
        $this->addPeriodDropDown($view, $pageId, $period, $activeView);
        $this->addViewMenu($view, $pageId, $period, $activeView);
        $this->addShortcutButton($view, $pageId, $period, $activeView);

        if ($pageId < 1) {
            // No page chosen yet, which is the state the module opens in. A
            // prompt rather than an error: nothing has gone wrong, the reader
            // simply has not clicked the tree yet.
            return $view->renderResponse('Module/NoPage');
        }

        if (false === BackendUtility::readPageAccess($pageId, $this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW))) {
            // The page uid arrives as a request parameter, so the permission
            // check is what stands between a hand-built URL and another
            // page's numbers. Same decision, same reason as
            // PageMetricsViewDataBuilder::build().
            return $view->assign('reason', 'page')->renderResponse('Module/Denied');
        }

        // Only once both guards above have passed. The button is the one
        // docheader control that goes somewhere, and offering a download on
        // the module's opening state or on a page the reader may not see
        // hands them a link that answers 400 or 403. The period and view
        // controls are harmless there because they only come back here.
        $this->addExportButton($view, $pageId, $period, $activeView, $moduleData);

        $includeSubpages = (bool) $moduleData?->get('includeSubpages');
        // NullSite where the uid resolves to no site, which the pages view
        // cannot answer for: a table of "the site's pages" needs a site.
        $site = $request->getAttribute('site');
        // Language 0, the same as the page module's own tiles: every surface
        // of this extension is single-language today and a module that
        // quietly picked another would disagree with the tiles about the
        // same page. The docheader language switch is an open question in
        // the spec, not an omission here.
        $viewData = $this->viewDataBuilder->build($pageId, 0, $period, $includeSubpages);

        // The same CSS and the same chart renderer the page module and the
        // widgets use. initializeStaticCharts() in sparkline.js picks up any
        // .pagemetrics-widget-chart on the page, so the module's chart needs
        // no code of its own.
        $this->pageRenderer->addCssFile('EXT:typo3_page_metrics/Resources/Public/Css/page-metrics.css');
        $this->pageRenderer->loadJavaScriptModule('@konradmichalik/page-metrics/sparkline.js');

        $view->assignMultiple([
            'pageId' => $pageId,
            'period' => $period,
            'activeView' => $activeView,
            'includeSubpages' => $includeSubpages,
            'viewData' => $viewData,
            'chartSeries' => $this->chartSeriesOf($viewData, $site instanceof Site ? $site : null),
            'sparklineLabels' => $this->labelProvider->all(),
            'annotations' => $this->annotationPayloadBuilder->build($viewData->annotations),
            // Only the view that reads it. The comparison is a second pass
            // over the same repositories, and paying for it on the page view
            // would put work behind a tab nobody opened.
            //
            // The raw moduleData value goes in, not a parsed one: which
            // baselines exist is the builder's own vocabulary, and an unknown
            // or missing key resolves there to the period immediately before,
            // which is what every other surface already compares against.
            'comparison' => ModuleView::Compare === $activeView
                ? $this->periodComparisonBuilder->build($pageId, 0, $period, self::baselineKeyOf($moduleData), $includeSubpages)
                : null,
            // Same rule as the comparison above: only the view that reads it
            // pays for it. The site comes off the request, where the backend
            // site resolver already put it from the same page uid, rather
            // than being looked up a second time.
            'pagesTable' => ModuleView::Pages === $activeView && $site instanceof Site
                ? $this->sitePagesTableBuilder->build($site, 0, $period, self::stringOf($moduleData, 'sort'), self::stringOf($moduleData, 'direction'), (int) ($request->getQueryParams()['tablePage'] ?? 1))
                : null,
            // Told apart from a table that is simply null, which the template
            // otherwise reads as "not built yet". A page belonging to no
            // configured site has an answer, and it is not that the feature
            // is unfinished: a table of a site's pages needs a site.
            'hasSite' => $site instanceof Site,
        ]);

        return $view->renderResponse($activeView->templateName());
    }

    /**
     * The chart draws the site's primary metric and nothing else. Every
     * metric is in the table below it, and overlaying five series in a view
     * whose whole point is being readable would undo the reason for building
     * the view.
     *
     * The site's metric, never this page's (see
     * MetricDefinitionCollector::primaryFor()). The rule used to move on to
     * the next metric where this page had no data for the first, so two
     * pages of one site charted different figures under the same heading and
     * a reader clicking from one to the other compared two things without
     * being told. A page with nothing recorded draws an empty chart instead,
     * which is what is true about it.
     *
     * Empty for a page belonging to no site, and for a site that counts
     * nothing: neither has a metric to lead with.
     *
     * The payload is the shape sparkline.js already reads for the dashboard
     * chart widget (see PageMetricsChartWidget), so no chart code is written
     * twice.
     *
     * @return list<array{label: string, color: string, unit: string, values: array<string, int|null>}>
     */
    private function chartSeriesOf(PageMetricsViewData $viewData, ?Site $site): array
    {
        $primary = null !== $site ? $this->metricDefinitionCollector->primaryFor($this->providers, $site) : null;

        if (null === $primary) {
            return [];
        }

        foreach ($viewData->tiles as $tile) {
            if ($tile->identifier === $primary->identifier) {
                return [['label' => $tile->label, 'color' => $tile->color, 'unit' => $tile->unit->value, 'values' => $tile->sparkline]];
            }
        }

        return [];
    }

    private static function baselineKeyOf(?ModuleData $moduleData): ?string
    {
        $baseline = $moduleData?->get('compareBaseline');

        return is_string($baseline) ? $baseline : null;
    }

    /**
     * moduleData holds whatever was last persisted for this user, which
     * survives an upgrade and can be anything a query string once carried.
     * Every reader of it validates its own keys; this only guarantees the
     * type they validate.
     */
    private static function stringOf(?ModuleData $moduleData, string $key): string
    {
        $value = $moduleData?->get($key);

        return is_string($value) ? $value : '';
    }

    private function moduleData(ServerRequestInterface $request): ?ModuleData
    {
        $moduleData = $request->getAttribute('moduleData');

        return $moduleData instanceof ModuleData ? $moduleData : null;
    }

    private function period(?ModuleData $moduleData): int
    {
        $period = (int) ($moduleData?->get('period') ?? self::DEFAULT_PERIOD);

        return in_array($period, self::PERIOD_OPTIONS, true) ? $period : self::DEFAULT_PERIOD;
    }

    private function view(?ModuleData $moduleData): ModuleView
    {
        $view = $moduleData?->get('view');

        return ModuleView::tryFrom(is_array($view) ? '' : (string) $view) ?? ModuleView::default();
    }

    /**
     * A dropdown button rather than a second docheader menu, which is not a
     * style choice: 13.4 renders every menu the registry holds, 14 renders
     * only the first and logs a warning about the rest. Two menus would have
     * worked on one supported version and silently dropped the period switch
     * on the other. A button bar holds as many dropdowns as it is given on
     * both, and it is what v14's own warning points at.
     */
    private function addPeriodDropDown(ModuleTemplate $view, int $pageId, int $period, ModuleView $activeView): void
    {
        $dropDown = GeneralUtility::makeInstance(DropDownButton::class);
        $dropDown->setLabel($this->translate('period.label'));
        // Without one of the two the button reports itself invalid and the
        // button bar refuses it. The words, not an icon: "Period" is not a
        // thing anyone recognises from a glyph, and the selected value has
        // to be readable without opening the dropdown.
        $dropDown->setShowLabelText(true);

        foreach (self::PERIOD_OPTIONS as $option) {
            $dropDown->addItem(
                GeneralUtility::makeInstance(DropDownRadio::class)
                    ->setActive($option === $period)
                    ->setHref($this->moduleUri($pageId, $option, $activeView))
                    ->setLabel($option.' '.$this->translate('period.days')),
            );
        }

        $view->getDocHeaderComponent()->getButtonBar()->addButton($dropDown, ButtonBar::BUTTON_POSITION_LEFT);
    }

    private function addViewMenu(ModuleTemplate $view, int $pageId, int $period, ModuleView $activeView): void
    {
        $menu = GeneralUtility::makeInstance(Menu::class);
        $menu->setIdentifier('pagemetrics_view');
        $menu->setLabel($this->translate('module.view.label'));

        foreach (ModuleView::cases() as $option) {
            $menu->addMenuItem(
                GeneralUtility::makeInstance(MenuItem::class)
                    ->setTitle($this->translate($option->labelKey()))
                    ->setHref($this->moduleUri($pageId, $period, $option))
                    ->setActive($option === $activeView),
            );
        }

        $view->getDocHeaderComponent()->getMenuRegistry()->addMenu($menu);
    }

    /**
     * Built server side because an AJAX route URI is token-signed and a
     * template cannot put back a token it cannot read. Its own route rather
     * than a mode on this one, for the reason AjaxRoutes.php gives: a link
     * carrying `period` and `view` through the module route would rewrite
     * the reader's stored module state rather than describe a download.
     *
     * Every parameter the views read between them, not the ones this view
     * happens to use: the sort is ignored by the page and compare views and
     * decides the whole file for the pages one, and a download that came out
     * in a different order from the table above it is not the export this
     * button promises. The pager is deliberately absent, because the export
     * is every row rather than the page of them on screen.
     */
    private function addExportButton(ModuleTemplate $view, int $pageId, int $period, ModuleView $activeView, ?ModuleData $moduleData): void
    {
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();
        $buttonBar->addButton(
            GeneralUtility::makeInstance(LinkButton::class)
                ->setHref((string) $this->uriBuilder->buildUriFromRoute('ajax_page_metrics_export', [
                    'id' => $pageId,
                    'period' => $period,
                    'view' => $activeView->value,
                    'includeSubpages' => (bool) $moduleData?->get('includeSubpages') ? '1' : '0',
                    'compareBaseline' => self::baselineKeyOf($moduleData) ?? '',
                    'sort' => self::stringOf($moduleData, 'sort'),
                    'direction' => self::stringOf($moduleData, 'direction'),
                ]))
                ->setTitle($this->translate('module.export'))
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-file-csv', IconSize::SMALL)),
            ButtonBar::BUTTON_POSITION_RIGHT,
        );
    }

    private function moduleUri(int $pageId, int $period, ModuleView $view): string
    {
        return (string) $this->uriBuilder->buildUriFromRoute('web_pagemetrics', [
            'id' => $pageId,
            'period' => $period,
            'view' => $view->value,
        ]);
    }

    /**
     * The bookmark carries the page, the period and the view, because a
     * bookmark that reopens the module on a different question is not one.
     *
     * Two spellings for two versions. TYPO3 14 deprecated adding the button
     * by hand in favour of setShortcutContext(), which 13.4 does not have.
     * Branched on the major version rather than on method_exists(), which
     * static analysis resolves against whichever core is installed and then
     * calls always true.
     */
    private function addShortcutButton(ModuleTemplate $view, int $pageId, int $period, ModuleView $activeView): void
    {
        $docHeader = $view->getDocHeaderComponent();
        $arguments = ['id' => $pageId, 'period' => $period, 'view' => $activeView->value];
        $displayName = $this->translate('module.mlang_tabs_tab');

        if ($this->typo3Version->getMajorVersion() >= 14) {
            $docHeader->setShortcutContext('web_pagemetrics', $displayName, $arguments);

            return;
        }

        $buttonBar = $docHeader->getButtonBar();
        $buttonBar->addButton(
            GeneralUtility::makeInstance(ShortcutButton::class)
                ->setRouteIdentifier('web_pagemetrics')
                ->setDisplayName($displayName)
                ->setArguments($arguments),
            ButtonBar::BUTTON_POSITION_RIGHT,
        );
    }

    private function translate(string $key): string
    {
        return $this->getLanguageService()->sL('LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:'.$key);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
