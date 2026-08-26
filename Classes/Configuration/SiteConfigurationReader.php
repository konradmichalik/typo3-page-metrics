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

namespace KonradMichalik\Typo3PageMetrics\Configuration;

use KonradMichalik\Typo3PageMetrics\Exception\InvalidSiteConfigurationException;
use TYPO3\CMS\Core\Site\Entity\Site;

use function is_array;
use function is_bool;
use function is_int;
use function sprintf;

/**
 * SiteConfigurationReader.
 *
 * Absence of the "pageMetrics" key means "not opted in" (a normal, expected
 * state) and returns a disabled default - only a malformed value under an
 * existing key is treated as a configuration error worth failing fast on.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SiteConfigurationReader
{
    public function read(Site $site): PageMetricsSiteConfiguration
    {
        $raw = $site->getConfiguration()['pageMetrics'] ?? null;

        if (null === $raw) {
            return new PageMetricsSiteConfiguration(false, [], true, [], 400, []);
        }

        if (!is_array($raw)) {
            throw new InvalidSiteConfigurationException(sprintf('Site "%s": "pageMetrics" must be a mapping.', $site->getIdentifier()), 1756000000);
        }

        $enabled = $raw['enabled'] ?? false;
        if (!is_bool($enabled)) {
            throw new InvalidSiteConfigurationException(sprintf('Site "%s": "pageMetrics.enabled" must be a boolean.', $site->getIdentifier()), 1756000001);
        }

        $sources = $raw['sources'] ?? [];
        if (!is_array($sources)) {
            throw new InvalidSiteConfigurationException(sprintf('Site "%s": "pageMetrics.sources" must be a mapping.', $site->getIdentifier()), 1756000002);
        }

        $resolution = $raw['resolution'] ?? [];
        if (!is_array($resolution)) {
            throw new InvalidSiteConfigurationException(sprintf('Site "%s": "pageMetrics.resolution" must be a mapping.', $site->getIdentifier()), 1756000003);
        }

        $dropQueryString = $resolution['dropQueryString'] ?? true;
        if (!is_bool($dropQueryString)) {
            throw new InvalidSiteConfigurationException(sprintf('Site "%s": "pageMetrics.resolution.dropQueryString" must be a boolean.', $site->getIdentifier()), 1756000004);
        }

        $keepQueryParams = $resolution['keepQueryParams'] ?? [];
        if (!is_array($keepQueryParams) || !array_is_list($keepQueryParams)) {
            throw new InvalidSiteConfigurationException(sprintf('Site "%s": "pageMetrics.resolution.keepQueryParams" must be a list.', $site->getIdentifier()), 1756000005);
        }

        $retentionDays = $raw['retentionDays'] ?? 400;
        if (!is_int($retentionDays) || $retentionDays < 1) {
            throw new InvalidSiteConfigurationException(sprintf('Site "%s": "pageMetrics.retentionDays" must be a positive integer.', $site->getIdentifier()), 1756000006);
        }

        $additionalHosts = $resolution['additionalHosts'] ?? [];
        if (!is_array($additionalHosts) || !array_is_list($additionalHosts)) {
            throw new InvalidSiteConfigurationException(sprintf('Site "%s": "pageMetrics.resolution.additionalHosts" must be a list.', $site->getIdentifier()), 1756000007);
        }

        /* @var array<string, array<string, mixed>> $sources */
        /* @var list<string> $keepQueryParams */
        /* @var list<string> $additionalHosts */
        return new PageMetricsSiteConfiguration($enabled, $sources, $dropQueryString, $keepQueryParams, $retentionDays, $additionalHosts);
    }
}
