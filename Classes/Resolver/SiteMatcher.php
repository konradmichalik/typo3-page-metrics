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

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function strlen;
use function strtolower;


/**
 * SiteMatcher.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */

final class SiteMatcher
{
    /**
     * @param array<Site> $sites
     */
    public function match(string $normalizedUrl, array $sites): ?Site
    {
        $urlParts = parse_url($normalizedUrl);

        if (false === $urlParts || !isset($urlParts['host'])) {
            return null;
        }

        foreach ($sites as $site) {
            $additionalHosts = $this->additionalHosts($site);

            if ($this->baseMatches($urlParts, $site->getBase(), $additionalHosts)) {
                return $site;
            }

            foreach ($site->getLanguages() as $language) {
                if ($this->baseMatches($urlParts, $language->getBase(), $additionalHosts)) {
                    return $site;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $urlParts
     * @param list<string>         $additionalHosts
     */
    private function baseMatches(array $urlParts, UriInterface $base, array $additionalHosts): bool
    {
        $baseHost = strtolower($base->getHost());
        $urlHost = strtolower((string) $urlParts['host']);

        if ('' === $baseHost || ($urlHost !== $baseHost && !in_array($urlHost, $additionalHosts, true))) {
            return false;
        }

        $basePath = rtrim($base->getPath(), '/');
        $urlPath = (string) ($urlParts['path'] ?? '/');

        if ('' === $basePath) {
            return true;
        }

        if (!str_starts_with($urlPath, $basePath)) {
            return false;
        }

        return strlen($basePath) === strlen($urlPath) || '/' === $urlPath[strlen($basePath)];
    }

    /**
     * Reads a site's own pageMetrics.resolution.additionalHosts directly
     * from its raw configuration rather than via SiteConfigurationReader:
     * match() is called once per candidate site while searching for the
     * owning site, so validation (and its exceptions) must stay scoped to
     * SiteConfigurationReader::read(), called once per site being imported.
     *
     * @return list<string>
     */
    private function additionalHosts(Site $site): array
    {
        $hosts = $site->getConfiguration()['pageMetrics']['resolution']['additionalHosts'] ?? [];

        if (!is_array($hosts)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $host): string => strtolower($host),
            array_filter($hosts, 'is_string'),
        ));
    }
}
