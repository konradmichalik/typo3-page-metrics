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

namespace KonradMichalik\Typo3PageMetrics\Resolver;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Domain-based and path-prefix languages are both just "a base a URL can
 * start with" in TYPO3's site model - there is no separate fallback branch,
 * the longest matching base path simply wins, so a specific "/en/" base
 * beats the default "/" base for the same request.
 */
final class LanguageMatcher
{
    public function match(string $normalizedUrl, Site $site): ?SiteLanguage
    {
        $urlParts = parse_url($normalizedUrl);

        if (false === $urlParts || !isset($urlParts['host'])) {
            return null;
        }

        $best = null;
        $bestLength = -1;

        foreach ($site->getLanguages() as $language) {
            $base = $language->getBase();
            $baseHost = strtolower($base->getHost());

            if ('' !== $baseHost && strtolower((string) $urlParts['host']) !== $baseHost) {
                continue;
            }

            $basePath = rtrim($base->getPath(), '/');
            $urlPath = (string) ($urlParts['path'] ?? '/');

            if ('' !== $basePath && !str_starts_with($urlPath, $basePath)) {
                continue;
            }

            $length = strlen($basePath);
            if ($length > $bestLength) {
                $best = $language;
                $bestLength = $length;
            }
        }

        return $best;
    }
}
