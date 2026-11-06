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

namespace KonradMichalik\Typo3PageMetrics\Configuration;

use KonradMichalik\Typo3PageMetrics\Exception\InvalidSiteConfigurationException;
use TYPO3\CMS\Core\Site\Entity\Site;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;
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
final readonly class SiteConfigurationReader
{
    public function __construct(
        private GoalConfigurationParser $goalConfigurationParser,
    ) {}

    public function read(Site $site): PageMetricsSiteConfiguration
    {
        $raw = $site->getConfiguration()['pageMetrics'] ?? null;

        if (null === $raw) {
            return new PageMetricsSiteConfiguration(false, [], true, [], 400, [], null, true, [], []);
        }

        $raw = $this->mapping($raw, $site, 'pageMetrics', 1756000000);
        $resolution = $this->mapping($raw['resolution'] ?? [], $site, 'pageMetrics.resolution', 1756000003);
        $annotations = $this->mapping($raw['annotations'] ?? [], $site, 'pageMetrics.annotations', 1756000008);

        /** @var array<string, array<string, mixed>> $providers */
        $providers = $this->mapping($raw['providers'] ?? [], $site, 'pageMetrics.providers', 1756000002);
        $metricFilters = $this->metricFilters($providers, $site, 1756000020);
        /** @var list<string> $keepQueryParams */
        $keepQueryParams = $this->listOf($resolution['keepQueryParams'] ?? [], $site, 'pageMetrics.resolution.keepQueryParams', 1756000005);
        /** @var list<string> $additionalHosts */
        $additionalHosts = $this->listOf($resolution['additionalHosts'] ?? [], $site, 'pageMetrics.resolution.additionalHosts', 1756000007);
        $goals = $this->listOf($raw['goals'] ?? [], $site, 'pageMetrics.goals', 1756000010);

        return new PageMetricsSiteConfiguration(
            $this->boolean($raw['enabled'] ?? false, $site, 'pageMetrics.enabled', 1756000001),
            $providers,
            $this->boolean($resolution['dropQueryString'] ?? true, $site, 'pageMetrics.resolution.dropQueryString', 1756000004),
            $keepQueryParams,
            $this->positiveInteger($raw['retentionDays'] ?? 400, $site, 'pageMetrics.retentionDays', 1756000006),
            $additionalHosts,
            // Null, not an error: a site that names no storage page keeps
            // every annotation it already has (they are read by site
            // identifier, never by pid), it just offers no "add marker"
            // shortcut until someone picks a page for new ones to live on.
            $this->optionalPageUid($annotations['storagePid'] ?? null, $site, 'pageMetrics.annotations.storagePid', 1756000009),
            // On by default: markers nobody has to maintain are the point,
            // and a site that wants none can say so.
            $this->boolean($annotations['automatic'] ?? true, $site, 'pageMetrics.annotations.automatic', 1756000011),
            $this->goalConfigurationParser->parse($site->getIdentifier(), $goals),
            $metricFilters,
        );
    }

    /**
     * The optional per-provider "metrics" allow list, read here rather than in
     * each provider's own ConfigurationResolver: it applies to every provider
     * a site can configure, including third-party ones this extension has no
     * resolver for and knows nothing else about.
     *
     * An entry naming a metric no provider offers is deliberately not an
     * error. Checking that would mean asking the providers, and they read
     * their own configuration through this class, so the dependency only goes
     * one way. Per-site metrics (goal conversions) are not known here either,
     * and an entry may well name a provider extension that is installed later.
     *
     * @param array<mixed> $providers
     *
     * @return array<string, list<string>>
     */
    private function metricFilters(array $providers, Site $site, int $code): array
    {
        $filters = [];

        foreach ($providers as $identifier => $config) {
            if (!is_array($config) || !isset($config['metrics'])) {
                continue;
            }

            $key = sprintf('pageMetrics.providers.%s.metrics', (string) $identifier);
            $entries = $config['metrics'];

            // An empty list is rejected with the malformed ones: its only
            // reading is "configure this provider, then show none of it",
            // which is what leaving the provider out already says.
            if (!is_array($entries) || !array_is_list($entries) || [] === $entries) {
                throw $this->invalid($site, $key, 'a non-empty list of metric identifiers or metric group names', $code);
            }

            $names = [];

            foreach ($entries as $entry) {
                if (!is_string($entry) || '' === $entry) {
                    throw $this->invalid($site, $key, 'a non-empty list of metric identifiers or metric group names', $code);
                }

                $names[] = $entry;
            }

            $filters[(string) $identifier] = $names;
        }

        return $filters;
    }

    /**
     * One shape of "this key is wrong" per type, rather than the same
     * if-and-throw written out once per key. The numeric code stays with the
     * call site: it identifies the option, not the kind of mistake, and an
     * installation may well be matching on it.
     *
     * @return array<mixed>
     */
    private function mapping(mixed $value, Site $site, string $key, int $code): array
    {
        if (!is_array($value)) {
            throw $this->invalid($site, $key, 'a mapping', $code);
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private function listOf(mixed $value, Site $site, string $key, int $code): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->invalid($site, $key, 'a list', $code);
        }

        return $value;
    }

    private function boolean(mixed $value, Site $site, string $key, int $code): bool
    {
        if (!is_bool($value)) {
            throw $this->invalid($site, $key, 'a boolean', $code);
        }

        return $value;
    }

    private function positiveInteger(mixed $value, Site $site, string $key, int $code): int
    {
        if (!is_int($value) || $value < 1) {
            throw $this->invalid($site, $key, 'a positive integer', $code);
        }

        return $value;
    }

    private function optionalPageUid(mixed $value, Site $site, string $key, int $code): ?int
    {
        if (null === $value) {
            return null;
        }

        if (!is_int($value) || $value < 1) {
            throw $this->invalid($site, $key, 'the uid of an existing page', $code);
        }

        return $value;
    }

    private function invalid(Site $site, string $key, string $expected, int $code): InvalidSiteConfigurationException
    {
        return new InvalidSiteConfigurationException(sprintf('Site "%s": "%s" must be %s.', $site->getIdentifier(), $key, $expected), $code);
    }
}
