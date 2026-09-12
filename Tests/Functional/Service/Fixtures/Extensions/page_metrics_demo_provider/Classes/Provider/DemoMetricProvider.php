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

namespace KonradMichalik\PageMetricsDemoProvider\Provider;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition};
use KonradMichalik\Typo3PageMetrics\Provider\MetricProviderInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * DemoMetricProvider.
 *
 * Test fixture standing in for a third-party metric provider: an identifier the
 * extension has no hard-coded display name for, reusing Matomo's "Page views"
 * label so the label-disambiguation path is exercised.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class DemoMetricProvider implements MetricProviderInterface
{
    public function getIdentifier(): string
    {
        return 'demo';
    }

    public function supports(Site $site): bool
    {
        return true;
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition('demo.pageviews', 'Page views', 'Demo page views.', 'actions-view', MetricUnit::Count, 10, '#0969da'),
        ];
    }

    public function getRateMetricDefinitions(): array
    {
        return [];
    }

    public function getExternalLink(Site $site, string $pageUrl, DateRange $range): ?string
    {
        return null;
    }

    public function fetch(Site $site, DateRange $range): iterable
    {
        return [];
    }
}
