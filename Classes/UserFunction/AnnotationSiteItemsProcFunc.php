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

namespace KonradMichalik\Typo3PageMetrics\UserFunction;

use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * AnnotationSiteItemsProcFunc.
 *
 * Populates the "Site" select field on tx_typo3pagemetrics_manual_annotation
 * records with every configured site - a plain select rather than a page-tree
 * position (like EXT:sys_note uses) because an annotation is a site-wide
 * marker, not tied to any one page, and picking the site explicitly avoids
 * having to walk the page tree back up to a root to answer "which site is
 * this record for" every time annotations are read.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class AnnotationSiteItemsProcFunc
{
    public function __construct(
        private SiteFinder $siteFinder,
    ) {}

    /**
     * @param array<string, mixed> $fieldInformation
     */
    public function populateSites(array &$fieldInformation): void
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            $fieldInformation['items'][] = [
                'label' => $site->getIdentifier(),
                'value' => $site->getIdentifier(),
            ];
        }
    }
}
