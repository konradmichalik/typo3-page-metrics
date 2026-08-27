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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Command\Fixtures;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, UrlMetric};
use KonradMichalik\Typo3PageMetrics\Source\MetricSourceInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * StubMetricSource.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class StubMetricSource implements MetricSourceInterface
{
    /**
     * @param list<UrlMetric> $metrics
     */
    public function __construct(
        private readonly array $metrics,
    ) {}

    public function getIdentifier(): string
    {
        return 'stub';
    }

    public function supports(Site $site): bool
    {
        return true;
    }

    public function getMetricDefinitions(): array
    {
        return [new MetricDefinition('stub.hits', 'Hits', 'Stub hits.', 'actions-view', MetricUnit::Count, 1, '#57606a')];
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
        yield from $this->metrics;
    }
}
