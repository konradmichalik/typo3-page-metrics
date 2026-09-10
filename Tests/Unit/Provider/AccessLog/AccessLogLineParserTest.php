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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Provider\AccessLog;

use KonradMichalik\Typo3PageMetrics\Provider\AccessLog\AccessLogLineParser;
use PHPUnit\Framework\TestCase;

/**
 * AccessLogLineParserTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AccessLogLineParserTest extends TestCase
{
    private AccessLogLineParser $subject;

    protected function setUp(): void
    {
        $this->subject = new AccessLogLineParser();
    }

    public function testParsesAStandardCombinedLogFormatLine(): void
    {
        $line = '127.0.0.1 - - [10/Oct/2026:13:55:36 +0000] "GET /about?utm_source=x HTTP/1.1" 200 1234 "https://example.com/" "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"';

        $entry = $this->subject->parse($line);

        self::assertNotNull($entry);
        self::assertSame('127.0.0.1', $entry->ipAddress);
        self::assertSame('2026-10-10', $entry->date->format('Y-m-d'));
        self::assertSame('GET', $entry->method);
        self::assertSame('/about?utm_source=x', $entry->path);
        self::assertSame(200, $entry->statusCode);
        self::assertSame('Mozilla/5.0 (Windows NT 10.0; Win64; x64)', $entry->userAgent);
    }

    public function testParsesAnIpv6RemoteAddress(): void
    {
        $line = '2001:db8::1 - - [10/Oct/2026:13:55:36 +0000] "GET / HTTP/2.0" 200 512 "-" "curl/8.0"';

        $entry = $this->subject->parse($line);

        self::assertNotNull($entry);
        self::assertSame('2001:db8::1', $entry->ipAddress);
    }

    public function testParsesARequestWithADashByteCount(): void
    {
        $line = '10.0.0.1 - - [10/Oct/2026:13:55:36 +0000] "GET /empty HTTP/1.1" 304 - "-" "Mozilla/5.0"';

        $entry = $this->subject->parse($line);

        self::assertNotNull($entry);
        self::assertSame(304, $entry->statusCode);
    }

    public function testParsesANonGetMethod(): void
    {
        $line = '10.0.0.1 - - [10/Oct/2026:13:55:36 +0000] "POST /form HTTP/1.1" 200 100 "-" "Mozilla/5.0"';

        $entry = $this->subject->parse($line);

        self::assertNotNull($entry);
        self::assertSame('POST', $entry->method);
    }

    public function testReturnsNullForAnEmptyLine(): void
    {
        self::assertNull($this->subject->parse(''));
    }

    public function testReturnsNullForAMalformedLineMissingTheRequestQuotes(): void
    {
        self::assertNull($this->subject->parse('this is not a log line at all'));
    }

    public function testReturnsNullForATruncatedLine(): void
    {
        $line = '127.0.0.1 - - [10/Oct/2026:13:55:36 +0000] "GET /about HTTP/1.1" 200';

        self::assertNull($this->subject->parse($line));
    }

    public function testReturnsNullForAnUnparseableTimestamp(): void
    {
        $line = '127.0.0.1 - - [not-a-date] "GET /about HTTP/1.1" 200 100 "-" "Mozilla/5.0"';

        self::assertNull($this->subject->parse($line));
    }
}
