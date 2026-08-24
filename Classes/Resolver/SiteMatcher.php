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

use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

final class SiteMatcher
{
    /**
     * @param iterable<Site> $sites
     */
    public function match(string $normalizedUrl, iterable $sites): ?Site
    {
        $urlParts = parse_url($normalizedUrl);

        if (false === $urlParts || !isset($urlParts['host'])) {
            return null;
        }

        foreach ($sites as $site) {
            if ($this->baseMatches($urlParts, $site->getBase())) {
                return $site;
            }

            foreach ($site->getLanguages() as $language) {
                if ($this->baseMatches($urlParts, $language->getBase())) {
                    return $site;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $urlParts
     */
    private function baseMatches(array $urlParts, UriInterface $base): bool
    {
        $baseHost = strtolower($base->getHost());

        if ('' === $baseHost || strtolower((string) $urlParts['host']) !== $baseHost) {
            return false;
        }

        $basePath = rtrim($base->getPath(), '/');
        $urlPath = (string) ($urlParts['path'] ?? '/');

        return '' === $basePath || str_starts_with($urlPath, $basePath);
    }
}
