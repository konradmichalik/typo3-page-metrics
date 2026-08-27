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

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * PageMetricsAccessChecker.
 *
 * Whether the current backend user may see the page-metrics tiles at all -
 * a single, deliberately dependency-free check reused by both
 * PageHeaderMetricsRenderer (so a user without access never gets the
 * placeholder, CSS or JS in the first place) and PageMetricsTilesController
 * (so the same user cannot bypass that by calling the AJAX route directly).
 * Visible by default: set via User TSconfig rather than a custom_options
 * permission checkbox (an allow-list with no built-in "deny"), which would
 * have hidden the tiles for every existing installation the moment this
 * check shipped, until someone explicitly re-granted access per group.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PageMetricsAccessChecker
{
    public function hasAccess(): bool
    {
        $disabled = $this->getBackendUser()->getTSConfig()['tx_typo3pagemetrics.']['disabled'] ?? '0';

        return '1' !== (string) $disabled;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
