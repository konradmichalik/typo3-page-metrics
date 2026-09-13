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

namespace KonradMichalik\Typo3PageMetrics\Analysis\Parser;

use DateTimeImmutable;

use function strpos;
use function substr;

/**
 * CombinedLogParser.
 *
 * Parses one line of Apache/nginx Combined Log Format, optionally
 * extended with a trailing virtual-host token (as produced by a
 * `LogFormat` ending in `%v`), which several fields in this extension's
 * URL/site resolution will need later.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class CombinedLogParser implements LogParserInterface
{
    private const LINE_PATTERN = '/^(\S+) \S+ \S+ \[([^\]]+)\] "((?:[^"\\\\]|\\\\.)*)" (\d{3}) (\S+)'
        .'(?: "((?:[^"\\\\]|\\\\.)*)" "((?:[^"\\\\]|\\\\.)*)")?(?: (\S+))?/';

    private const REQUEST_LINE_PATTERN = '/^(\S+)\s+(\S+)\s+(\S+)$/';

    /**
     * The month names Combined Log Format writes, which are always these
     * English abbreviations regardless of the server's locale.
     */
    private const MONTHS = [
        'Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04',
        'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08',
        'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12',
    ];

    /**
     * The date of a line, read out of the bracketed timestamp alone, or
     * null when that cannot be done without parsing the whole line.
     *
     * For callers that read far more lines than they keep: deciding on the
     * date costs a strpos and a substring here, against a regex and a
     * DateTimeImmutable in parseLine(). The date is the one the server
     * wrote, in the server's own offset, which is exactly what
     * LogLine::$timestamp formats back to, so the two never disagree about
     * which day a line belongs to.
     *
     * Null means "ask parseLine()", never "not in any range": a line this
     * cannot read a date out of may still be a perfectly good entry in an
     * unexpected shape.
     */
    public function peekDate(string $line): ?string
    {
        $opening = strpos($line, '[');

        if (false === $opening) {
            return null;
        }

        $month = self::MONTHS[substr($line, $opening + 4, 3)] ?? null;

        if (null === $month) {
            return null;
        }

        return substr($line, $opening + 8, 4).'-'.$month.'-'.substr($line, $opening + 1, 2);
    }

    public function parseLine(string $line): ?LogLine
    {
        $line = rtrim($line, "\r\n");
        if ('' === $line || 1 !== preg_match(self::LINE_PATTERN, $line, $matches)) {
            return null;
        }

        $timestamp = DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $matches[2]);
        if (false === $timestamp) {
            return null;
        }

        [$method, $target, $protocol] = $this->parseRequestLine($matches[3]);
        $bytesSent = '-' === $matches[5] ? null : (int) $matches[5];
        $referer = $this->nullIfEmptyOrDash($matches[6] ?? '');
        $host = $this->nullIfEmptyOrDash($matches[8] ?? '');

        return new LogLine(
            $matches[1],
            $timestamp,
            $method,
            $target,
            $protocol,
            (int) $matches[4],
            $bytesSent,
            $referer,
            $matches[7] ?? '',
            $host,
        );
    }

    /**
     * @return array{0: string|null, 1: string, 2: string|null}
     */
    private function parseRequestLine(string $requestLine): array
    {
        if (1 === preg_match(self::REQUEST_LINE_PATTERN, $requestLine, $matches)) {
            return [$matches[1], $matches[2], $matches[3]];
        }

        return [null, $requestLine, null];
    }

    private function nullIfEmptyOrDash(string $value): ?string
    {
        return '' === $value || '-' === $value ? null : $value;
    }
}
