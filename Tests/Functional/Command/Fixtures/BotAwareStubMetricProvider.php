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

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\Bucket;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, UrlMetric};
use KonradMichalik\Typo3PageMetrics\Provider\MetricProviderInterface;
use KonradMichalik\Typo3PageMetrics\Service\BotAgentDetailCollector;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * BotAwareStubMetricProvider.
 *
 * StubMetricProvider's counterpart for exercising ImportCommand's
 * collector-flush and transaction wiring: fetch() both yields the given
 * UrlMetric list (same as StubMetricProvider) and tells the given
 * BotAgentDetailCollector about one agent hit, the same way
 * AccessLogMetricProvider does for a real classified log line. Cannot
 * subclass StubMetricProvider - that class is final - so it duplicates its
 * small body directly instead.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class BotAwareStubMetricProvider implements MetricProviderInterface
{
    /**
     * @param list<UrlMetric> $metrics
     */
    public function __construct(
        private array $metrics,
        private BotAgentDetailCollector $collector,
        private DateTimeImmutable $agentHitDate,
        private string $agentHitUrl,
        private Bucket $agentHitBucket,
        private string $agentHitAgent,
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
        return [new MetricDefinition('bot.ai_crawler', 'AI crawlers', 'Stub bot hits.', 'tabler-spider', MetricUnit::Count, 1, '#ae3ec9')];
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
        $this->collector->add($this->agentHitDate, $this->agentHitUrl, $this->agentHitBucket, $this->agentHitAgent, false);

        yield from $this->metrics;
    }
}
