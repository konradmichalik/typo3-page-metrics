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

namespace KonradMichalik\Typo3PageMetrics\Service;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\Bucket;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\UnresolvedReason;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{ResolvedUrl, UnresolvedUrl};
use KonradMichalik\Typo3PageMetrics\Persistence\BotAgentDailyRepository;
use KonradMichalik\Typo3PageMetrics\Resolver\Resolver;

use function implode;

/**
 * BotAgentDetailCollector.
 *
 * The agent-level sidecar to AccessLogMetricProvider's own bucket-level
 * bot.<bucket> UrlMetric emissions (see that class): agent detail does not
 * fit UrlMetric's (date, url, metric, value) shape (spec §3.4), so it is
 * collected here during fetch() via add() and written by ImportCommand via
 * flush(), once the whole provider's lines have been read.
 *
 * Aggregates in memory first, by (date, url, bucket, agent), rather than
 * resolving and writing on every add() call: the same URL/bucket/agent
 * combination recurs many times across a real log, and folding those
 * repeats down to one resolve() call plus one upsert() per combination is
 * both cheaper and matches DailyMetricsRepository's own "aggregate before
 * upsert" rule (see ImportCommand::importOneProviderForSite()'s docblock).
 *
 * Resolution happens here, not by AccessLogMetricProvider itself, and reuses
 * the same shared Resolver instance ImportCommand's own resolution loop
 * already warmed for these exact URLs earlier in the same run - Resolver's
 * own per-instance cache (keyed by normalized URL) makes the repeat
 * resolution in flush() an O(1) cache hit, not a second real resolution.
 *
 * Not final: AccessLogMetricProviderTest mocks this collaborator in a pure
 * unit test (see AccessLogConfigurationResolver's docblock for the same
 * reasoning) - PHP cannot generate a test double for a final class.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class BotAgentDetailCollector
{
    /**
     * @var array<string, array{date: DateTimeImmutable, url: string, bucket: Bucket, agent: string, hits: int, hitsSuspicious: int}>
     */
    private array $aggregated = [];

    public function __construct(
        private readonly Resolver $resolver,
        private readonly BotAgentDailyRepository $repository,
    ) {}

    public function add(DateTimeImmutable $date, string $url, Bucket $bucket, string $agent, bool $suspicious): void
    {
        $key = implode('|', [$date->format('Y-m-d'), $url, $bucket->value, $agent]);

        $this->aggregated[$key] ??= [
            'date' => $date,
            'url' => $url,
            'bucket' => $bucket,
            'agent' => $agent,
            'hits' => 0,
            'hitsSuspicious' => 0,
        ];

        ++$this->aggregated[$key]['hits'];
        if ($suspicious) {
            ++$this->aggregated[$key]['hitsSuspicious'];
        }
    }

    /**
     * Drops everything accumulated so far without writing it. Callers use
     * this wherever the accumulated state can no longer be attributed to
     * the provider and site that produced it: between sites in one import
     * run, and after a provider threw mid-fetch(), so a partially collected
     * batch never ends up flushed under whatever provider or site runs next
     * in the same command invocation.
     */
    public function reset(): void
    {
        $this->aggregated = [];
    }

    /**
     * @param list<string> $keepQueryParams
     */
    public function flush(string $siteIdentifier, bool $dropQueryString, array $keepQueryParams): void
    {
        /**
         * Re-aggregated by write identity, not by the raw URL `add()` keyed
         * on: several source URLs (query variants, a redirect and its
         * target) can resolve to the same page, and every row always
         * contributes to the same site-wide page-zero rollup regardless of
         * resolution - upsert() is replace-, not add-, semantics, so writing
         * per raw-URL row would have later rows silently overwrite earlier
         * ones instead of summing.
         *
         * @var array<string, array{pageUid: int, languageUid: int, bucket: Bucket, agent: string, date: DateTimeImmutable, hits: int, hitsSuspicious: int}>
         */
        $byWriteIdentity = [];

        foreach ($this->aggregated as $row) {
            $result = $this->resolver->resolve($row['url'], $dropQueryString, $keepQueryParams);

            if ($result instanceof UnresolvedUrl && UnresolvedReason::ForeignHost === $result->reason) {
                continue;
            }

            $byWriteIdentity = $this->foldIntoWriteIdentity($byWriteIdentity, 0, 0, $row);

            if ($result instanceof ResolvedUrl) {
                $byWriteIdentity = $this->foldIntoWriteIdentity($byWriteIdentity, $result->pageUid, $result->languageUid, $row);
            }
        }

        foreach ($byWriteIdentity as $row) {
            $this->repository->upsert($siteIdentifier, $row['pageUid'], $row['languageUid'], $row['bucket']->value, $row['agent'], $row['date'], $row['hits'], $row['hitsSuspicious']);
        }

        $this->aggregated = [];
    }

    /**
     * @param array<string, array{pageUid: int, languageUid: int, bucket: Bucket, agent: string, date: DateTimeImmutable, hits: int, hitsSuspicious: int}> $byWriteIdentity
     * @param array{date: DateTimeImmutable, url: string, bucket: Bucket, agent: string, hits: int, hitsSuspicious: int}                                   $row
     *
     * @return array<string, array{pageUid: int, languageUid: int, bucket: Bucket, agent: string, date: DateTimeImmutable, hits: int, hitsSuspicious: int}>
     */
    private function foldIntoWriteIdentity(array $byWriteIdentity, int $pageUid, int $languageUid, array $row): array
    {
        $key = implode('|', [$row['date']->format('Y-m-d'), $pageUid, $languageUid, $row['bucket']->value, $row['agent']]);

        $entry = $byWriteIdentity[$key] ?? [
            'pageUid' => $pageUid,
            'languageUid' => $languageUid,
            'bucket' => $row['bucket'],
            'agent' => $row['agent'],
            'date' => $row['date'],
            'hits' => 0,
            'hitsSuspicious' => 0,
        ];

        $entry['hits'] += $row['hits'];
        $entry['hitsSuspicious'] += $row['hitsSuspicious'];

        return [...$byWriteIdentity, $key => $entry];
    }
}
