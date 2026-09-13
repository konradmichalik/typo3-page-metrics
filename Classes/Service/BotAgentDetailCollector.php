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
 * collected here during fetch() via add() and written by ImportCommand,
 * which drains each finished day via flushDaysBefore() as it consumes
 * fetch() and takes the rest with flush() once the read is over.
 *
 * Aggregates in memory first, by (date, url, bucket, agent), rather than
 * resolving and writing on every add() call: the same URL/bucket/agent
 * combination recurs many times across a real log, and folding those
 * repeats down to one resolve() call plus one upsert() per combination is
 * both cheaper and matches DailyMetricsRepository's own "aggregate before
 * upsert" rule (see ImportCommand::importOneProviderForSite()'s docblock).
 *
 * What it aggregates is bounded by the run, not by the range, which is why
 * flushDaysBefore() exists alongside flush(): an entry costs about a
 * kilobyte, and a first import of the 400-day default on a heavily crawled
 * site reaches two million of them. Measured at 400 days x 500 URLs x 10
 * agents, holding the whole run peaks at 1945 MB against 13 MB when the
 * command drains each day as the provider finishes it.
 *
 * The date is kept as the 'Y-m-d' string the key already carries rather
 * than as the DateTimeImmutable add() is handed, which is another 129 of
 * those bytes per entry. Nothing between add() and the upsert does date
 * arithmetic on it: it is only ever compared to another day string and
 * formatted back out.
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
     * @var array<string, array{date: string, url: string, bucket: Bucket, agent: string, hits: int, hitsSuspicious: int}>
     */
    private array $aggregated = [];

    public function __construct(
        private readonly Resolver $resolver,
        private readonly BotAgentDailyRepository $repository,
    ) {}

    public function add(DateTimeImmutable $date, string $url, Bucket $bucket, string $agent, bool $suspicious): void
    {
        $day = $date->format('Y-m-d');
        $key = implode('|', [$day, $url, $bucket->value, $agent]);

        $this->aggregated[$key] ??= [
            'date' => $day,
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
        $due = $this->aggregated;
        $this->aggregated = [];

        $this->write($due, $siteIdentifier, $dropQueryString, $keepQueryParams);
    }

    /**
     * Writes out and drops every day older than $day, leaving the days the
     * provider may still be reading lines for untouched.
     *
     * This is what keeps the buffer bounded by a couple of days rather than
     * by the length of the import range. It is the caller's job, not this
     * class's, to say which day is still open: resolution needs the site
     * identifier and its query-string configuration, which only
     * ImportCommand holds, and only the caller sees the provider's own day
     * boundary as it consumes fetch().
     *
     * Safe against the replace semantics of the underlying upsert only
     * because the boundary is a whole day. A day written here is one the
     * provider has finished and will not add to again, so its row is final;
     * a day written halfway would be overwritten by its own remainder
     * rather than added to.
     *
     * @param list<string> $keepQueryParams
     */
    public function flushDaysBefore(string $siteIdentifier, bool $dropQueryString, array $keepQueryParams, DateTimeImmutable $day): void
    {
        $boundary = $day->format('Y-m-d');
        $due = [];
        $open = [];

        foreach ($this->aggregated as $key => $row) {
            if ($row['date'] < $boundary) {
                $due[$key] = $row;

                continue;
            }

            $open[$key] = $row;
        }

        if ([] === $due) {
            return;
        }

        $this->aggregated = $open;

        $this->write($due, $siteIdentifier, $dropQueryString, $keepQueryParams);
    }

    /**
     * @param array<string, array{date: string, url: string, bucket: Bucket, agent: string, hits: int, hitsSuspicious: int}> $rows
     * @param list<string>                                                                                                   $keepQueryParams
     */
    private function write(array $rows, string $siteIdentifier, bool $dropQueryString, array $keepQueryParams): void
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
         * @var array<string, array{pageUid: int, languageUid: int, bucket: Bucket, agent: string, date: string, hits: int, hitsSuspicious: int}>
         */
        $byWriteIdentity = [];

        foreach ($rows as $row) {
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
            // The one place a day needs to be an object again, and it is
            // transient here rather than held per buffered entry.
            $this->repository->upsert($siteIdentifier, $row['pageUid'], $row['languageUid'], $row['bucket']->value, $row['agent'], new DateTimeImmutable($row['date']), $row['hits'], $row['hitsSuspicious']);
        }
    }

    /**
     * @param array<string, array{pageUid: int, languageUid: int, bucket: Bucket, agent: string, date: string, hits: int, hitsSuspicious: int}> $byWriteIdentity
     * @param array{date: string, url: string, bucket: Bucket, agent: string, hits: int, hitsSuspicious: int}                                   $row
     *
     * @return array<string, array{pageUid: int, languageUid: int, bucket: Bucket, agent: string, date: string, hits: int, hitsSuspicious: int}>
     */
    private function foldIntoWriteIdentity(array $byWriteIdentity, int $pageUid, int $languageUid, array $row): array
    {
        $key = implode('|', [$row['date'], $pageUid, $languageUid, $row['bucket']->value, $row['agent']]);

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
