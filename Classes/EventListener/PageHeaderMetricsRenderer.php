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

namespace KonradMichalik\Typo3PageMetrics\EventListener;

use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\PageRenderer;

use function htmlspecialchars;
use function sprintf;

/**
 * PageHeaderMetricsRenderer.
 *
 * Injects only a lightweight placeholder into the Web > Page module's header
 * area via TYPO3 core's ModifyPageLayoutContentEvent - the same extension
 * point EXT:sys_note uses to show notes there. No site resolution, no
 * database query and no Fluid rendering happens here: client-side JS fetches
 * the actual tiles from PageMetricsTilesController immediately after the
 * page has loaded, so this extension's work never sits on the page module's
 * own critical rendering path.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PageHeaderMetricsRenderer
{
    public function __construct(
        private UriBuilder $uriBuilder,
        private PageRenderer $pageRenderer,
    ) {}

    #[AsEventListener('page-metrics-header')]
    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $request = $event->getRequest();
        $pageId = (int) ($request->getQueryParams()['id'] ?? 0);

        if ($pageId <= 0) {
            return;
        }

        $this->pageRenderer->addCssFile('EXT:page_metrics/Resources/Public/Css/page-metrics.css');
        $this->pageRenderer->loadJavaScriptModule('@konradmichalik/page-metrics/sparkline.js');

        $ajaxUrl = htmlspecialchars((string) $this->uriBuilder->buildUriFromRoute('ajax_page_metrics_tiles'), ENT_QUOTES);

        $event->addHeaderContent(sprintf(
            '<div class="pagemetrics-header pagemetrics-header-placeholder" data-page-id="%d" data-period="30" data-ajax-url="%s"></div>',
            $pageId,
            $ajaxUrl,
        ));
    }
}
