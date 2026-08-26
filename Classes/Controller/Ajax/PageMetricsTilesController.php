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

namespace KonradMichalik\Typo3PageMetrics\Controller\Ajax;

use KonradMichalik\Typo3PageMetrics\Service\PageMetricsViewDataBuilder;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;

/**
 * PageMetricsTilesController.
 *
 * All the work PageHeaderMetricsRenderer used to do synchronously inside
 * ModifyPageLayoutContentEvent now happens here instead, in a separate
 * request fired by client-side JS after the page module has already
 * rendered - site resolution, permission checks and every dailySeries()
 * query run here, off the page module's own critical rendering path.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PageMetricsTilesController
{
    public function __construct(
        private PageMetricsViewDataBuilder $viewDataBuilder,
        private BackendViewFactory $backendViewFactory,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $pageId = (int) ($request->getQueryParams()['id'] ?? 0);
        $periodDays = (int) ($request->getQueryParams()['period'] ?? 30);

        $viewData = $this->viewDataBuilder->build($pageId, 0, $periodDays);

        if (!$viewData->hasPage) {
            return new HtmlResponse('');
        }

        $view = $this->backendViewFactory->create($request, ['konradmichalik/typo3-page-metrics']);
        $view->assignMultiple([
            'pageId' => $viewData->pageId,
            'tiles' => $viewData->tiles,
            'unresolvedCount' => $viewData->unresolvedCount,
            'periodDays' => $viewData->periodDays,
            'periodOptions' => $viewData->periodOptions,
        ]);

        return new HtmlResponse($view->render('PageHeader/Metrics'));
    }
}
