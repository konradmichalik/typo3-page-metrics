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

namespace KonradMichalik\Typo3PageMetrics\Resolver;

use TYPO3\CMS\Core\Site\Entity\{Site, SiteLanguage};

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function strlen;
use function strtolower;

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

        $additionalHosts = $this->additionalHosts($site);
        $best = null;
        $bestLength = -1;

        foreach ($site->getLanguages() as $language) {
            $length = $this->matchLength($urlParts, $language, $additionalHosts);

            if (null !== $length && $length > $bestLength) {
                $best = $language;
                $bestLength = $length;
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $urlParts
     * @param list<string>         $additionalHosts
     */
    private function matchLength(array $urlParts, SiteLanguage $language, array $additionalHosts): ?int
    {
        $base = $language->getBase();
        $baseHost = strtolower($base->getHost());
        $urlHost = strtolower((string) $urlParts['host']);

        if ('' !== $baseHost && $urlHost !== $baseHost && !in_array($urlHost, $additionalHosts, true)) {
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

    /**
     * @return list<string>
     */
    private function additionalHosts(Site $site): array
    {
        $hosts = $site->getConfiguration()['pageMetrics']['resolution']['additionalHosts'] ?? [];

        if (!is_array($hosts)) {
            return [];
        }

        return array_values(array_map(
            strtolower(...),
            array_filter($hosts, is_string(...)),
        ));
    }
}
