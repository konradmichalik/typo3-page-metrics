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

namespace KonradMichalik\Typo3PageMetrics\Service;

use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * ModuleLinkBuilder.
 *
 * The way from a glance to the reading: the link the page module's tile row
 * and the dashboard widgets carry into Web > Page metrics. Null rather than
 * a link wherever that module would refuse the reader, because a link to a
 * page that answers "you may not see this" is worse than no link at all.
 *
 * Three refusals, the same three the module itself makes: the switch that
 * hides the tiles (PageMetricsAccessChecker), TYPO3's own module permission
 * for a module declared 'access' => 'user', and PAGE_SHOW on the page the
 * link names. The last one matters for the widgets, which name a site's root
 * page rather than a page the reader picked.
 *
 * The link carries the page and nothing else. `period` and `view` are
 * moduleData keys, and BackendModuleValidator persists a query parameter
 * named like one before the module is reached, so passing the surface's own
 * period would not pre-select it for this visit, it would overwrite what
 * this reader gets on every later one.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ModuleLinkBuilder
{
    private const MODULE = 'web_pagemetrics';

    public function __construct(
        private UriBuilder $uriBuilder,
        private ModuleProvider $moduleProvider,
        private PageMetricsAccessChecker $accessChecker,
    ) {}

    public function forPage(int $pageUid): ?string
    {
        if ($pageUid < 1 || !$this->mayOpenModule() || !$this->maySeePage($pageUid)) {
            return null;
        }

        return (string) $this->uriBuilder->buildUriFromRoute(self::MODULE, ['id' => $pageUid]);
    }

    /**
     * The same decision per site, keyed by site identifier, for the widgets:
     * one dashboard can carry several sites and a reader may be allowed the
     * root page of one of them and not of the next. A site with no link has
     * no entry, so a template asking for a missing key gets nothing, which
     * is what it should render.
     *
     * @param array<string, Site> $sites
     *
     * @return array<string, string>
     */
    public function forSites(array $sites): array
    {
        $links = [];

        foreach ($sites as $site) {
            $link = $this->forPage($site->getRootPageId());

            if (null !== $link) {
                $links[$site->getIdentifier()] = $link;
            }
        }

        return $links;
    }

    private function mayOpenModule(): bool
    {
        if (!$this->accessChecker->hasAccess()) {
            return false;
        }

        // ModuleProvider rather than a hand-rolled check on groupMods: it is
        // what BackendModuleValidator asks before it lets the route through,
        // and it is spelled the same way in 13.4 and 14 even though what
        // 'access' => 'user' resolves to differs between them.
        return $this->moduleProvider->accessGranted(self::MODULE, $this->getBackendUser());
    }

    private function maySeePage(int $pageUid): bool
    {
        return false !== BackendUtility::readPageAccess($pageUid, $this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW));
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
