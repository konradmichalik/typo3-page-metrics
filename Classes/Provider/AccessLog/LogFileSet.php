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

use KonradMichalik\Typo3PageMetrics\Analysis\Parser\CombinedLogParser;
use KonradMichalik\Typo3PageMetrics\Import\LogFileReader;
use RuntimeException;

use function array_keys;
use function glob;
use function is_file;
use function uasort;

/**
 * LogFileSet.
 *
 * The files one AccessLogConfiguration::$pathPattern matches, in the order
 * they have to be read, and the lines out of them a given range can still
 * want.
 *
 * Separate from AccessLogMetricProvider because it answers a different
 * question: which bytes exist and in what order, rather than what the
 * lines in them add up to.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class LogFileSet
{
    /**
     * How many opening lines of a file may fail to parse before it is
     * treated as undatable for ordering purposes.
     */
    private const ORDERING_PROBE_LINES = 10;

    public function __construct(
        private LogFileReader $logFileReader,
        private CombinedLogParser $lineParser,
    ) {}

    /**
     * Every line of the matched set that the requested range could still
     * want, oldest file first.
     *
     * @param array<string, true> $allowedDates
     *
     * @return iterable<string>
     */
    public function linesInRange(string $pathPattern, array $allowedDates): iterable
    {
        foreach ($this->orderedFiles($pathPattern) as $file) {
            try {
                foreach ($this->logFileReader->readLines($file) as $rawLine) {
                    // The set matched by pathPattern is read in full on
                    // every run, while an incremental run wants one day out
                    // of it: a site rotating daily for a year hands 365
                    // files to a range that covers one. Rejecting a line on
                    // its date alone skips a regex, a DateTimeImmutable and
                    // a bot classification for every line of every archive.
                    // Measured on a 1.44M-line, 120-day log: 1.7s to read
                    // and date-check every line, against 6.4s to parse them
                    // all. A line whose date cannot be read cheaply is
                    // passed on for the parser to judge.
                    $date = $this->lineParser->peekDate($rawLine);

                    if (null === $date || isset($allowedDates[$date])) {
                        yield $rawLine;
                    }
                }
            } catch (RuntimeException) {
                // One unreadable file must not cost the site its whole
                // import. A glob over a rotation directory routinely picks up
                // a file the web user cannot read, and logrotate can delete
                // or recompress one between the glob and the read of a long
                // first run. The reader throws for both, and skipping keeps
                // the behaviour the provider had before it used the reader.
                continue;
            }
        }
    }

    /**
     * Oldest file first, by the date of the first entry each one contains.
     *
     * The order is not a nicety. A day is emitted once the reader has moved
     * two days past it (see DailyAggregationBuffer::finishedDates()), so
     * reading an older file after a newer one produces a day that was
     * already written, which the buffer refuses outright.
     *
     * By content rather than by modification time: mtime looks like the
     * obvious key and is not one. A restore from backup, an rsync without
     * -t or a plain cp rewrites every mtime to the same instant, and the
     * order silently becomes the glob's. File names are no better, since
     * rotation schemes disagree (access.log.1, access.log-20261001.gz) and
     * several of them sort the wrong way round alphabetically.
     *
     * Reading one line per file to find out costs a single decompression
     * block on the gzipped ones, against a full read of every file straight
     * afterwards.
     *
     * @return list<string>
     */
    private function orderedFiles(string $pathPattern): array
    {
        $startDates = [];

        foreach (glob($pathPattern) ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $startDates[$file] = $this->firstEntryDate($file);
        }

        // A file whose opening lines will not parse keeps its glob position,
        // behind everything that could be dated: it cannot be placed, and
        // guessing would be worse than leaving it where it was.
        uasort($startDates, static fn (?string $left, ?string $right): int => [null === $left, $left] <=> [null === $right, $right]);

        return array_keys($startDates);
    }

    /**
     * The date of the first entry this file contains, or null when none of
     * its opening lines parse. A rotated log can begin with a truncated
     * line, so this looks at a few before giving up.
     */
    private function firstEntryDate(string $file): ?string
    {
        $inspected = 0;

        try {
            foreach ($this->logFileReader->readLines($file) as $rawLine) {
                $line = $this->lineParser->parseLine($rawLine);

                if (null !== $line) {
                    return $line->timestamp->format('Y-m-d');
                }

                if (++$inspected >= self::ORDERING_PROBE_LINES) {
                    break;
                }
            }
        } catch (RuntimeException) {
            // Unreadable here means unreadable in linesInRange() too,
            // where it is skipped; there is nothing to place.
            return null;
        }

        return null;
    }
}
