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

namespace KonradMichalik\Typo3PageMetrics\Provider\AccessLog;

use KonradMichalik\Typo3PageMetrics\Configuration\SiteConfigurationReader;
use TYPO3\CMS\Core\Site\Entity\Site;

use function is_array;
use function is_string;

/**
 * AccessLogConfigurationResolver.
 *
 * Reads this extension's own "pageMetrics.providers.accesslog" configuration.
 *
 * Not final: mocked as an AccessLogMetricProvider collaborator in a pure
 * unit test, and PHP cannot generate a test double for a final class.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class AccessLogConfigurationResolver
{
    public function __construct(
        private readonly SiteConfigurationReader $siteConfigurationReader,
    ) {}

    public function resolve(Site $site): ?AccessLogConfiguration
    {
        $config = $this->siteConfigurationReader->read($site)->providers['accesslog'] ?? null;

        if (!is_array($config) || !is_string($config['pathPattern'] ?? null) || '' === $config['pathPattern']) {
            return null;
        }

        return new AccessLogConfiguration($config['pathPattern']);
    }
}
