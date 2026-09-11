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
            ip: $matches[1],
            timestamp: $timestamp,
            method: $method,
            target: $target,
            protocol: $protocol,
            status: (int) $matches[4],
            bytesSent: $bytesSent,
            referer: $referer,
            userAgent: $matches[7] ?? '',
            host: $host,
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
