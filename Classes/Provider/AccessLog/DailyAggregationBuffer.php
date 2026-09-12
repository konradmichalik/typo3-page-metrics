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

namespace KonradMichalik\Typo3PageMetrics\Provider\AccessLog;

use DateTimeImmutable;

use function array_keys;
use function array_unique;
use function count;
use function sort;
use function sprintf;

/**
 * DailyAggregationBuffer.
 *
 * The running per-day totals AccessLogMetricProvider builds while it reads a
 * log, and the decision of when a day is finished enough to emit.
 *
 * A mutable object rather than arrays threaded through the provider's own
 * methods, for two reasons. AccessLogMetricProvider is `final readonly`, so
 * it cannot hold this as state itself; and handing the buffers to a helper
 * by value would copy the visitor set on every single line, since the helper
 * writing into it is what ends copy-on-write sharing. One short-lived
 * instance per fetch() call avoids both without passing anything by
 * reference.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class DailyAggregationBuffer
{
    /**
     * How far behind the newest day seen a day has to fall before it counts
     * as finished. One day of slack absorbs the ordinary case of a log whose
     * lines straddle midnight, or a rotated file that ends a few entries
     * into the next day.
     */
    private const CLOSING_LAG_DAYS = 1;

    /** @var array<string, array<string, int>> date => path => hit count */
    private array $pageviews = [];

    /** @var array<string, array<string, array<string, true>>> date => path => visitor key set */
    private array $visitors = [];

    /** @var array<string, array<string, array<string, int>>> date => bucket => url => hit count */
    private array $botHits = [];

    private ?string $newestDate = null;

    /** @var array<string, true> dates already handed to the caller */
    private array $emitted = [];

    /**
     * Recomputed only when the watermark actually moves, which is once per
     * calendar day rather than once per line: the date arithmetic and the
     * sort behind it are cheap individually and ruinous a million times.
     */
    private ?string $closingBefore = null;

    /**
     * @return bool whether the watermark moved, so the caller knows a
     *              finishedDates() check is worth making at all
     */
    public function advanceTo(string $date): bool
    {
        if (null !== $this->newestDate && $date <= $this->newestDate) {
            return false;
        }

        $this->newestDate = $date;
        $this->closingBefore = (new DateTimeImmutable($date))
            ->modify(sprintf('-%d days', self::CLOSING_LAG_DAYS))
            ->format('Y-m-d');

        return true;
    }

    public function addPageview(string $date, string $path, string $visitorKey): void
    {
        $this->pageviews[$date][$path] = ($this->pageviews[$date][$path] ?? 0) + 1;
        $this->visitors[$date][$path][$visitorKey] = true;
    }

    public function addBotHit(string $date, string $bucket, string $url): void
    {
        $this->botHits[$date][$bucket][$url] = ($this->botHits[$date][$bucket][$url] ?? 0) + 1;
    }

    /**
     * The days that can be emitted and dropped now.
     *
     * A day is finished once the reader has moved a full day past it. That
     * holds as long as the files are read in roughly chronological order,
     * which is what AccessLogMetricProvider::orderedFiles() arranges and what
     * every rotation scheme produces. A log whose lines are badly out of
     * order can still have a day reopened after it was emitted, and its
     * visit count for that day is then counted once per reopening rather
     * than once - an accepted, bounded inaccuracy for a metric whose own
     * description already calls itself an approximation, and the price of
     * not holding a year of visitor sets in memory at once.
     *
     * @return list<string>
     */
    public function finishedDates(): array
    {
        if (null === $this->closingBefore) {
            return [];
        }

        $finished = [];
        foreach ($this->bufferedDates() as $date) {
            if ($date < $this->closingBefore) {
                $finished[] = $date;
            }
        }

        return $finished;
    }

    /**
     * Every day currently held, oldest first. Bot-only days count: a day can
     * carry crawler hits and no human page view at all.
     *
     * @return list<string>
     */
    public function bufferedDates(): array
    {
        $dates = array_unique([...array_keys($this->pageviews), ...array_keys($this->botHits)]);
        // sort() reindexes, so the result is already a list.
        sort($dates);

        return $dates;
    }

    /**
     * @return array<string, int> path => hit count
     */
    public function pageviewsOn(string $date): array
    {
        return $this->pageviews[$date] ?? [];
    }

    public function visitorCountOn(string $date, string $path): int
    {
        return count($this->visitors[$date][$path] ?? []);
    }

    /**
     * @return array<string, array<string, int>> bucket => url => hit count
     */
    public function botHitsOn(string $date): array
    {
        return $this->botHits[$date] ?? [];
    }

    public function forget(string $date): void
    {
        unset($this->pageviews[$date], $this->visitors[$date], $this->botHits[$date]);
        $this->emitted[$date] = true;
    }

    /**
     * A day that has already been emitted cannot be added to again, and the
     * caller has to be told rather than quietly given a wrong number.
     *
     * Emitting is irreversible from here: the consumer sums page views, so a
     * reopened day would double-count them, its visitor set is gone so the
     * distinct count would double-count too, and the unresolved-URL store
     * replaces rather than sums, so it would end up holding only the second
     * fragment. The alternative to refusing is a plausible-looking number
     * that is wrong by an amount nobody can see.
     */
    public function isClosed(string $date): bool
    {
        return isset($this->emitted[$date]);
    }
}
