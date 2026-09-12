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

namespace KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy;

/**
 * AgentMatcher.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AgentMatcher
{
    /**
     * Returns the first needle from the list that occurs in the user
     * agent string as a case-insensitive substring, in list order, or
     * null if none match.
     *
     * @param list<string> $needles
     */
    public function match(string $userAgent, array $needles): ?string
    {
        foreach ($needles as $needle) {
            if (false !== stripos($userAgent, $needle)) {
                return $needle;
            }
        }

        return null;
    }
}
