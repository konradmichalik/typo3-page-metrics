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
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;

use function count;

/**
 * DayOrderedBotAwareMetricProvider.
 *
 * Models AccessLogMetricProvider's own emission shape: it reads a day's
 * lines into the collector and only then yields that day's UrlMetric, day
 * by day and oldest first, so the consumer sees the same day boundary a
 * real log read produces.
 *
 * Counts the bot-agent rows already in the table at the start of each day
 * it emits, which is what makes "the previous day was drained while the
 * read was still running" observable from a test at all: the count is taken
 * from inside fetch(), before the command has reached its write phase.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class DayOrderedBotAwareMetricProvider implements MetricProviderInterface
{
    /**
     * Bot-agent row count seen once the consumer had taken each day, in
     * emission order.
     *
     * @var list<int>
     */
    public array $rowsSeenPerDay = [];

    /**
     * @param list<DateTimeImmutable> $days
     * @param positive-int|null       $throwAfterDays stop the read partway through, the way an unreadable log file does
     */
    public function __construct(
        private readonly array $days,
        private readonly BotAgentDetailCollector $collector,
        private readonly ConnectionPool $connectionPool,
        private readonly ?int $throwAfterDays,
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
        $emitted = 0;

        foreach ($this->days as $day) {
            $this->collector->add($day, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot', false);

            yield new UrlMetric('https://example.com/about', $day, 'bot.ai_crawler', 1);

            // Control is back here only once the consumer has taken the day
            // above and done whatever it does per day, so this is the count
            // after that day's boundary was crossed, not before it.
            $this->rowsSeenPerDay[] = $this->countRows();

            if (++$emitted === $this->throwAfterDays && $emitted < count($this->days)) {
                throw new RuntimeException('Simulated provider failure partway through the log file.', 1103478512);
            }
        }
    }

    private function countRows(): int
    {
        return (int) $this->connectionPool
            ->getQueryBuilderForTable('tx_typo3pagemetrics_bot_agent_daily')
            ->count('uid')
            ->from('tx_typo3pagemetrics_bot_agent_daily')
            ->executeQuery()
            ->fetchOne();
    }
}
