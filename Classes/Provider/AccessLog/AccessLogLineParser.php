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

/**
 * AccessLogLineParser.
 *
 * Parses one line of the Apache/Nginx "combined" log format - the de facto
 * standard both servers default to, unlike a custom LogFormat/log_format
 * directive there is nothing installation-specific to configure here. A
 * line that does not match (malformed, truncated by a concurrent write, or
 * simply not a log line) is skipped by returning null rather than throwing:
 * a multi-million-line file having a handful of unparseable lines is normal,
 * not exceptional.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class AccessLogLineParser
{
    private const PATTERN = '/^(\S+) \S+ \S+ \[([^\]]+)\] "([A-Z]+) (\S+)(?: [^"]*)?" (\d{3}) (?:\d+|-) "[^"]*" "([^"]*)"/';

    public function parse(string $line): ?AccessLogEntry
    {
        if (1 !== preg_match(self::PATTERN, $line, $matches)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $matches[2]);

        if (false === $date) {
            return null;
        }

        return new AccessLogEntry(
            $matches[1],
            $date,
            $matches[3],
            $matches[4],
            (int) $matches[5],
            $matches[6],
        );
    }
}
