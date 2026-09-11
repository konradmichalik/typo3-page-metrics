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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Analysis\Aggregate;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Aggregate\Aggregator;
use KonradMichalik\Typo3PageMetrics\Analysis\Parser\LogLine;
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\{Bucket, Classification};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * AggregatorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(Aggregator::class)]
final class AggregatorTest extends TestCase
{
    #[Test]
    public function groupsAgentHitsByDayBucketAndAgent(): void
    {
        $aggregator = new Aggregator();
        $classification = new Classification(Bucket::AiCrawler, 'ClaudeBot');

        $aggregator->add($this->line('/a', 200), $classification, false);
        $aggregator->add($this->line('/b', 404), $classification, false);
        $aggregator->add($this->line('/c', 403), $classification, false);
        $aggregator->add($this->line('/wp-login.php', 404), $classification, true);

        $rows = $aggregator->agentDailyAggregates();

        self::assertCount(1, $rows);
        self::assertSame('2026-08-23', $rows[0]->date->format('Y-m-d'));
        self::assertSame(Bucket::AiCrawler, $rows[0]->bucket);
        self::assertSame('ClaudeBot', $rows[0]->agent);
        self::assertSame(4, $rows[0]->hits);
        self::assertSame(1, $rows[0]->hitsOk);
        self::assertSame(1, $rows[0]->hitsForbidden);
        self::assertSame(1, $rows[0]->hitsSuspicious);
    }

    #[Test]
    public function keepsDifferentAgentsInTheSameBucketSeparate(): void
    {
        $aggregator = new Aggregator();

        $aggregator->add($this->line('/a', 200), new Classification(Bucket::AiCrawler, 'ClaudeBot'), false);
        $aggregator->add($this->line('/a', 200), new Classification(Bucket::AiCrawler, 'GPTBot'), false);

        $rows = $aggregator->agentDailyAggregates();

        self::assertCount(2, $rows);
    }

    #[Test]
    public function groupsUrlHitsByDayBucketStatusAndUrl(): void
    {
        $aggregator = new Aggregator();
        $classification = new Classification(Bucket::AiCrawler, 'ClaudeBot');

        $aggregator->add($this->line('/products/foo?ref=x', 200), $classification, false);
        $aggregator->add($this->line('/products/foo?ref=x', 200), $classification, false);
        $aggregator->add($this->line('/products/foo?ref=x', 404), $classification, false);

        $rows = $aggregator->urlDailyAggregates();

        self::assertCount(2, $rows);

        $ok = array_values(array_filter($rows, static fn ($row) => 200 === $row->status));
        self::assertCount(1, $ok);
        self::assertSame('/products/foo?ref=x', $ok[0]->url);
        self::assertSame(2, $ok[0]->hits);

        $notFound = array_values(array_filter($rows, static fn ($row) => 404 === $row->status));
        self::assertCount(1, $notFound);
        self::assertSame(1, $notFound[0]->hits);
    }

    #[Test]
    public function separatesAggregatesByCalendarDay(): void
    {
        $aggregator = new Aggregator();
        $classification = new Classification(Bucket::AiCrawler, 'ClaudeBot');

        $aggregator->add($this->line('/a', 200, '2026-08-23 23:59:00'), $classification, false);
        $aggregator->add($this->line('/a', 200, '2026-08-24 00:01:00'), $classification, false);

        self::assertCount(2, $aggregator->agentDailyAggregates());
    }

    private function line(string $target, int $status, string $time = '2026-08-23 10:00:00'): LogLine
    {
        return new LogLine(
            ip: '216.73.217.0',
            timestamp: new DateTimeImmutable($time),
            method: 'GET',
            target: $target,
            protocol: 'HTTP/1.1',
            status: $status,
            bytesSent: 100,
            referer: null,
            userAgent: 'ClaudeBot/1.0',
            host: 'www.move-elevator.de',
        );
    }
}
