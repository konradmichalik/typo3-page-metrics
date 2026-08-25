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

namespace KonradMichalik\Typo3PageMetrics\Controller;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

class PageMetricsController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SiteFinder $siteFinder,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $pageId = (int) ($request->getQueryParams()['id'] ?? 0);
        $site = $this->resolveSite($pageId);

        $moduleTemplate->assignMultiple([
            'pageId' => $pageId,
            'hasPage' => $pageId > 0 && null !== $site,
        ]);
        $moduleTemplate->setTitle('Page Metrics');

        return $moduleTemplate->renderResponse('PageMetrics/Index');
    }

    private function resolveSite(int $pageId): ?Site
    {
        if ($pageId < 1) {
            return null;
        }

        try {
            return $this->siteFinder->getSiteByPageId($pageId);
        } catch (\Throwable) {
            return null;
        }
    }
}
