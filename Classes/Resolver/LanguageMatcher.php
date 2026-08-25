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

use TYPO3\CMS\Core\Site\Entity\{Site, SiteLanguage};

use function strlen;

/**
 * LanguageMatcher.
 *
 * Domain-based and path-prefix languages are both just "a base a URL can
 * start with" in TYPO3's site model - there is no separate fallback branch,
 * the longest matching base path simply wins, so a specific "/en/" base
 * beats the default "/" base for the same request.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
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
            $length = $this->matchLength($urlParts, $language);

            if (null !== $length && $length > $bestLength) {
                $best = $language;
                $bestLength = $length;
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $urlParts
     */
    private function matchLength(array $urlParts, SiteLanguage $language): ?int
    {
        $base = $language->getBase();
        $baseHost = strtolower($base->getHost());

        if ('' !== $baseHost && strtolower((string) $urlParts['host']) !== $baseHost) {
            return null;
        }

        $basePath = rtrim($base->getPath(), '/');
        $urlPath = (string) ($urlParts['path'] ?? '/');

        if ('' === $basePath) {
            return 0;
        }

        if (!str_starts_with($urlPath, $basePath)) {
            return null;
        }

        if (strlen($basePath) !== strlen($urlPath) && '/' !== $urlPath[strlen($basePath)]) {
            return null;
        }

        return strlen($basePath);
    }
}
