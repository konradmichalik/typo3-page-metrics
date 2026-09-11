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

namespace KonradMichalik\Typo3PageMetrics\Analysis\Aggregate;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Parser\LogLine;
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\{Bucket, Classification};

/**
 * Aggregator.
 *
 * Rolls up classified log lines into per-day totals, grouped the way the
 * persistence layer will need them: by (day, bucket, agent) for the
 * agent breakdown, and by (day, bucket, status, url) for the "where does
 * traffic land" report. Holds everything in memory for one import run;
 * callers read the results back out once the whole batch has been added.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class Aggregator
{
    /**
     * @var array<string, array{date: DateTimeImmutable, bucket: Bucket, agent: string, hits: int, hitsOk: int, hitsForbidden: int, hitsSuspicious: int}>
     */
    private array $agentTotals = [];

    /**
     * @var array<string, array{date: DateTimeImmutable, bucket: Bucket, status: int, url: string, hits: int}>
     */
    private array $urlTotals = [];

    public function add(LogLine $line, Classification $classification, bool $suspicious): void
    {
        $date = $line->timestamp->setTime(0, 0);

        $this->addAgentHit($date, $classification, $line->status, $suspicious);
        $this->addUrlHit($date, $classification->bucket, $line->status, $line->target);
    }

    /**
     * @return list<AgentDailyAggregate>
     */
    public function agentDailyAggregates(): array
    {
        return array_values(array_map(
            static fn (array $row): AgentDailyAggregate => new AgentDailyAggregate(
                date: $row['date'],
                bucket: $row['bucket'],
                agent: $row['agent'],
                hits: $row['hits'],
                hitsOk: $row['hitsOk'],
                hitsForbidden: $row['hitsForbidden'],
                hitsSuspicious: $row['hitsSuspicious'],
            ),
            $this->agentTotals,
        ));
    }

    /**
     * @return list<UrlDailyAggregate>
     */
    public function urlDailyAggregates(): array
    {
        return array_values(array_map(
            static fn (array $row): UrlDailyAggregate => new UrlDailyAggregate(
                date: $row['date'],
                url: $row['url'],
                bucket: $row['bucket'],
                status: $row['status'],
                hits: $row['hits'],
            ),
            $this->urlTotals,
        ));
    }

    private function addAgentHit(DateTimeImmutable $date, Classification $classification, int $status, bool $suspicious): void
    {
        $key = implode('|', [$date->format('Y-m-d'), $classification->bucket->value, $classification->agent]);

        $this->agentTotals[$key] ??= [
            'date' => $date,
            'bucket' => $classification->bucket,
            'agent' => $classification->agent,
            'hits' => 0,
            'hitsOk' => 0,
            'hitsForbidden' => 0,
            'hitsSuspicious' => 0,
        ];

        ++$this->agentTotals[$key]['hits'];
        if ($status >= 200 && $status < 300) {
            ++$this->agentTotals[$key]['hitsOk'];
        }
        if (403 === $status) {
            ++$this->agentTotals[$key]['hitsForbidden'];
        }
        if ($suspicious) {
            ++$this->agentTotals[$key]['hitsSuspicious'];
        }
    }

    private function addUrlHit(DateTimeImmutable $date, Bucket $bucket, int $status, string $url): void
    {
        $key = implode('|', [$date->format('Y-m-d'), $bucket->value, (string) $status, $url]);

        $this->urlTotals[$key] ??= [
            'date' => $date,
            'bucket' => $bucket,
            'status' => $status,
            'url' => $url,
            'hits' => 0,
        ];

        ++$this->urlTotals[$key]['hits'];
    }
}
