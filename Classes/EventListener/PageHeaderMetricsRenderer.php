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

namespace KonradMichalik\Typo3PageMetrics\EventListener;

use KonradMichalik\Typo3PageMetrics\Service\{PageMetricsAccessChecker, PageMetricsUserPreferences};
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;

use function htmlspecialchars;
use function sprintf;

/**
 * PageHeaderMetricsRenderer.
 *
 * Injects a fold-away toggle and, unless the editor has folded the block
 * away, a lightweight placeholder into the Web > Page module's header area
 * via TYPO3 core's ModifyPageLayoutContentEvent - the same extension point
 * EXT:sys_note uses to show notes there. No site resolution, no database
 * query and no Fluid rendering happens here: client-side JS fetches the
 * actual tiles from PageMetricsTilesController immediately after the page
 * has loaded, so this extension's work never sits on the page module's own
 * critical rendering path.
 *
 * A collapsed header gets no placeholder at all, so it costs nothing beyond
 * the toggle itself. Whether it is collapsed is the editor's own preference
 * (PageMetricsUserPreferences); whether the block exists at all is the
 * administrator's (PageMetricsAccessChecker). Neither implies the other.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PageHeaderMetricsRenderer
{
    private const LL = 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:';

    /**
     * Inline rather than through the icon API: this markup is built as a
     * string for a header slot that takes no Fluid view, the same way the
     * placeholder below it is, and pulling in IconFactory to render two
     * static glyphs would put an avoidable dependency on the page module's
     * synchronous path. Tabler Icons (MIT), see
     * Resources/Public/Icons/TABLER_LICENSE.txt.
     */
    private const ICON_CHEVRON_UP = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l6 -6l6 6" /></svg>';

    private const ICON_METRICS = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c1.34 0 2.62 .28 3.84 .83" /><path d="M18 16.5l1.5 1.5" /><path d="M15.5 14.5a2.5 2.5 0 1 0 5 0a2.5 2.5 0 0 0 -5 0" /></svg>';

    public function __construct(
        private UriBuilder $uriBuilder,
        private PageRenderer $pageRenderer,
        private PageMetricsAccessChecker $accessChecker,
        private PageMetricsUserPreferences $preferences,
    ) {}

    #[AsEventListener('page-metrics-header')]
    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $request = $event->getRequest();
        $pageId = (int) ($request->getQueryParams()['id'] ?? 0);

        if ($pageId <= 0 || !$this->accessChecker->hasAccess()) {
            return;
        }

        $this->pageRenderer->addCssFile('EXT:typo3_page_metrics/Resources/Public/Css/page-metrics.css');
        $this->pageRenderer->loadJavaScriptModule('@konradmichalik/page-metrics/sparkline.js');

        $ajaxUrl = htmlspecialchars((string) $this->uriBuilder->buildUriFromRoute('ajax_page_metrics_tiles'), \ENT_QUOTES);
        $preferencesUrl = htmlspecialchars((string) $this->uriBuilder->buildUriFromRoute('ajax_page_metrics_preferences'), \ENT_QUOTES);
        $collapsed = $this->preferences->isCollapsed();

        // The placeholder is what the client fetches into, so a collapsed
        // header simply does not get one: the point of folding the block away
        // is that it stops costing a request, and loading it only to hide it
        // would spend exactly what the editor asked to stop spending.
        $bodyId = sprintf('pagemetrics-header-body-%d', $pageId);

        // Expanded, the toolbar and its toggle come from the Fluid template
        // the AJAX route renders. The toggle is emitted here in both states
        // and pinned to the top right by CSS, so it lands beside the options
        // button without being part of the region it folds away.
        $body = $collapsed
            ? ''
            : sprintf(
                '<div id="%s" class="pagemetrics-header-placeholder" data-page-id="%d" data-period="30" data-include-subpages="0" data-ajax-url="%s"></div>',
                $bodyId,
                $pageId,
                $ajaxUrl,
            );

        $body .= $this->buildToggle($collapsed, $bodyId);

        // The toggle is a sibling of the body, never a child of it. It used
        // to ride along in the toolbar the AJAX response renders, which is
        // inside the very region it folds away: collapsing took the control
        // with it and left no way back except a reload.
        $event->addHeaderContent(sprintf(
            '<div class="pagemetrics-header%s" data-page-id="%d" data-body-id="%s" data-ajax-url="%s" data-preferences-url="%s">%s</div>',
            $collapsed ? ' pagemetrics-header-collapsed' : '',
            $pageId,
            $bodyId,
            $ajaxUrl,
            $preferencesUrl,
            $body,
        ));
    }

    private function buildToggle(bool $collapsed, string $bodyId): string
    {
        $languageService = $this->getLanguageService();
        $label = $languageService->sL(self::LL.($collapsed ? 'collapse.show' : 'collapse.hide'));
        $hint = $languageService->sL(self::LL.($collapsed ? 'collapse.show.hint' : 'collapse.hide.hint'));
        $altLabel = $languageService->sL(self::LL.($collapsed ? 'collapse.hide' : 'collapse.show'));
        $altHint = $languageService->sL(self::LL.($collapsed ? 'collapse.hide.hint' : 'collapse.show.hint'));

        $icon = $collapsed ? self::ICON_METRICS : self::ICON_CHEVRON_UP;
        $altIcon = $collapsed ? self::ICON_CHEVRON_UP : self::ICON_METRICS;

        // Icon only, so the words live in the accessible name and in the
        // tooltip rather than being optional extras on a visible label. All
        // three pairs travel with the element: pressing it makes it mean the
        // opposite, and the client swaps them without another round trip.
        //
        // The icon is in that set because it is the only part of the control
        // that says which way it points. It used to be rendered here alone,
        // so a header folded away in the browser kept the chevron that had
        // just pointed up at an open block, and only a reload put the right
        // glyph back.
        return sprintf(
            '<button type="button" class="pagemetrics-options-trigger pagemetrics-collapse-toggle" data-collapse-toggle'
            .' data-hint-title="%s" data-hint-text="%s" data-hint-title-alt="%s" data-hint-text-alt="%s"'
            .' data-icon-alt="%s" aria-expanded="%s" aria-controls="%s" aria-label="%s">%s</button>',
            htmlspecialchars($label, \ENT_QUOTES),
            htmlspecialchars($hint, \ENT_QUOTES),
            htmlspecialchars($altLabel, \ENT_QUOTES),
            htmlspecialchars($altHint, \ENT_QUOTES),
            htmlspecialchars($altIcon, \ENT_QUOTES),
            $collapsed ? 'false' : 'true',
            htmlspecialchars($bodyId, \ENT_QUOTES),
            htmlspecialchars($label, \ENT_QUOTES),
            $icon,
        );
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
