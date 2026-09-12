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

namespace KonradMichalik\Typo3PageMetrics\Provider;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricDefinition;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * MetricDefinitionCollector.
 *
 * What one provider offers for one site: its static definitions plus, where
 * it implements SiteAwareMetricProviderInterface, the ones that only exist
 * because this site configured them. Every consumer asks this rather than
 * the provider directly, so "does this provider have per-site metrics" is
 * decided in one place instead of at each of the five call sites.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MetricDefinitionCollector
{
    /**
     * @return list<MetricDefinition>
     */
    public function forSite(MetricProviderInterface $provider, Site $site): array
    {
        if (!$provider instanceof SiteAwareMetricProviderInterface) {
            return $provider->getMetricDefinitions();
        }

        return [...$provider->getMetricDefinitions(), ...$provider->getSiteMetricDefinitions($site)];
    }
}
