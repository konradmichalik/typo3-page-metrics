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

namespace KonradMichalik\Typo3PageMetrics\Source\Matomo;

use KonradMichalik\Typo3PageMetrics\Configuration\SiteConfigurationReader;
use TYPO3\CMS\Core\Site\Entity\Site;

use function is_array;
use function is_string;

/**
 * MatomoConfigurationResolver.
 *
 * Reads this extension's own "pageMetrics.sources.matomo" configuration.
 *
 * Not final: Task 11 mocks this as a MatomoMetricSource collaborator in a
 * pure unit test, and PHP cannot generate a test double for a final class.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class MatomoConfigurationResolver
{
    public function __construct(
        private readonly SiteConfigurationReader $siteConfigurationReader,
    ) {}

    public function resolve(Site $site): ?MatomoConfiguration
    {
        $ownConfig = $this->siteConfigurationReader->read($site)->sources['matomo'] ?? null;

        if (is_array($ownConfig) && $this->hasRequiredOwnKeys($ownConfig)) {
            return new MatomoConfiguration(
                (string) $ownConfig['endpoint'],
                (int) $ownConfig['siteId'],
                (string) $ownConfig['tokenAuth'],
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function hasRequiredOwnKeys(array $config): bool
    {
        return isset($config['endpoint'], $config['siteId'], $config['tokenAuth'])
            && is_string($config['endpoint']) && '' !== $config['endpoint']
            && is_string($config['tokenAuth']) && '' !== $config['tokenAuth']
            && is_numeric($config['siteId']);
    }
}
