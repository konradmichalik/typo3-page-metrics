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

namespace KonradMichalik\Typo3PageMetrics\Analysis\Guard;

use KonradMichalik\Typo3PageMetrics\Analysis\Parser\LogLine;

/**
 * PathHeuristicGuard.
 *
 * Stage-1 spoofing guard (spec §7.2): flags requests to paths no
 * legitimate crawler would ever fetch — credential dumps, admin panels,
 * VCS metadata. A hit here is a strong signal that the claimed
 * User-Agent is not genuine, independent of which bucket it matched.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PathHeuristicGuard implements SpoofingGuardInterface
{
    private const SUSPICIOUS_PATH_PATTERN = '#(^|/)\.(env|git|aws|ssh|svn|htpasswd)|'
        .'/(id_rsa|id_ed25519|secrets?\.json|credentials|localhost\.key|'
        .'firebase-adminsdk[^/]*\.json|dump\.sql|backup\.sql|db\.sql)|'
        .'\.(key|pem|p12|pfx|sql|bak|old|swp)$|'
        .'/(wp-login\.php|wp-admin|xmlrpc\.php|phpmyadmin|adminer|'
        .'server-status|actuator|eval-stdin\.php)#i';

    public function isSuspicious(LogLine $line): bool
    {
        return 1 === preg_match(self::SUSPICIOUS_PATH_PATTERN, $line->path());
    }
}
