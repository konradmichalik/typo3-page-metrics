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
use RuntimeException;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * BotAwareFailingMetricProvider.
 *
 * Models a real access-log provider that dies partway through a log file:
 * it hands the collector one agent hit, yields one UrlMetric, and only then
 * throws - so ImportCommand's catch block is reached with the collector
 * already holding state that will never legitimately be flushed.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class BotAwareFailingMetricProvider implements MetricProviderInterface
{
    public function __construct(
        private BotAgentDetailCollector $collector,
        private DateTimeImmutable $agentHitDate,
        private string $agentHitUrl,
        private Bucket $agentHitBucket,
        private string $agentHitAgent,
    ) {}

    public function getIdentifier(): string
    {
        return 'failing';
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

        yield new UrlMetric($this->agentHitUrl, $this->agentHitDate, 'bot.ai_crawler', 1);

        throw new RuntimeException('Simulated provider failure partway through the log file.', 3196824551);
    }
}
