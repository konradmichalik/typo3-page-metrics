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

namespace KonradMichalik\Typo3PageMetrics\Source\Ga4;

use KonradMichalik\Typo3PageMetrics\Configuration\SiteConfigurationReader;
use TYPO3\CMS\Core\Site\Entity\Site;

use function is_array;
use function is_file;
use function is_readable;
use function is_string;
use function json_decode;

/**
 * Ga4ConfigurationResolver.
 *
 * Reads "pageMetrics.sources.ga4" (propertyId, serviceAccountKeyFilePath)
 * and parses the referenced service-account JSON key file. The key file's
 * path - never its contents - belongs in site config; this is the only
 * place that reads the file, so Ga4AuthTokenProvider only ever sees the
 * already-parsed client_email/private_key pair.
 *
 * Not final: Task 4 mocks this as a Ga4MetricSource collaborator in a
 * pure unit test, and PHP cannot generate a test double for a final class.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class Ga4ConfigurationResolver
{
    public function __construct(
        private readonly SiteConfigurationReader $siteConfigurationReader,
    ) {}

    public function resolve(Site $site): ?Ga4Configuration
    {
        $config = $this->siteConfigurationReader->read($site)->sources['ga4'] ?? null;

        if (!is_array($config) || !$this->hasRequiredKeys($config)) {
            return null;
        }

        $keyFileContents = $this->readKeyFile((string) $config['serviceAccountKeyFilePath']);

        if (null === $keyFileContents) {
            return null;
        }

        $decoded = json_decode($keyFileContents, true);

        if (!is_array($decoded) || !is_string($decoded['client_email'] ?? null) || '' === $decoded['client_email']
            || !is_string($decoded['private_key'] ?? null) || '' === $decoded['private_key']
        ) {
            return null;
        }

        return new Ga4Configuration((string) $config['propertyId'], $decoded['client_email'], $decoded['private_key']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function hasRequiredKeys(array $config): bool
    {
        return isset($config['propertyId'], $config['serviceAccountKeyFilePath'])
            && is_string($config['propertyId']) && '' !== $config['propertyId']
            && is_string($config['serviceAccountKeyFilePath']) && '' !== $config['serviceAccountKeyFilePath'];
    }

    private function readKeyFile(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return false !== $contents ? $contents : null;
    }
}
