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
 * PageMetricsUserPreferences.
 *
 * What an editor has decided about their own view of the tiles, as opposed
 * to what an administrator has decided about the feature (see
 * PageMetricsAccessChecker, which reads User TSconfig). The two are
 * deliberately separate: "not available" and "not right now" are different
 * statements, and a collapsed header must not look like a revoked feature
 * to anyone reading the code later.
 *
 * Stored in the backend user's own uc array. That is TYPO3's documented home
 * for preferences ("User Settings (= preferences)" on
 * AbstractUserAuthentication::$uc), it is public, non-@internal API, and it
 * is identical in 13.4 and 14. BackendUserConfiguration wraps the same array
 * more conveniently but is marked @internal in both versions, which is not a
 * contract this extension should be depending on for something this small.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PageMetricsUserPreferences
{
    private const UC_KEY = 'tx_typo3pagemetrics';
    private const COLLAPSED = 'collapsed';

    public function isCollapsed(): bool
    {
        return (bool) ($this->getBackendUser()->uc[self::UC_KEY][self::COLLAPSED] ?? false);
    }

    public function setCollapsed(bool $collapsed): void
    {
        $backendUser = $this->getBackendUser();
        $backendUser->uc[self::UC_KEY][self::COLLAPSED] = $collapsed;
        $backendUser->writeUC();
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
