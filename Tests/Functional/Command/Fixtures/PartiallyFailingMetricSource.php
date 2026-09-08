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
use RuntimeException;
use TYPO3\CMS\Core\Site\Entity\Site;

use function sprintf;

/**
 * PartiallyFailingMetricSource.
 *
 * Throws when fetching for a given site identifier and yields normally for
 * every other site, used to prove that one site's failure does not abort
 * the whole import command run.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PartiallyFailingMetricSource implements MetricSourceInterface
{
    /**
     * @param list<UrlMetric> $metrics
     */
    public function __construct(
        private string $failingSiteIdentifier,
        private array $metrics,
    ) {}

    public function getIdentifier(): string
    {
        return 'partial';
    }

    public function supports(Site $site): bool
    {
        return true;
    }

    public function getMetricDefinitions(): array
    {
        return [new MetricDefinition('partial.hits', 'Hits', 'Partial hits.', 'actions-view', MetricUnit::Count, 1, '#57606a')];
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
        if ($site->getIdentifier() === $this->failingSiteIdentifier) {
            throw new RuntimeException(sprintf('Simulated source failure for site "%s".', $site->getIdentifier()), 7370234399);
        }

        yield from $this->metrics;
    }
}
