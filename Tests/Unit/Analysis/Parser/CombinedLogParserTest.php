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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Analysis\Parser;

use KonradMichalik\Typo3PageMetrics\Analysis\Parser\CombinedLogParser;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * CombinedLogParserTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(CombinedLogParser::class)]
final class CombinedLogParserTest extends TestCase
{
    private CombinedLogParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CombinedLogParser();
    }

    #[Test]
    public function parsesARealAccessLogLine(): void
    {
        $line = '216.73.217.0 - - [23/Aug/2026:23:59:57 +0200] "GET /robots.txt HTTP/1.1" 200 5731 "-" '
            .'"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) '
            .'Chrome/131.0.0.0 Safari/537.36; ClaudeBot/1.0; +claudebot@anthropic.com)" www.move-elevator.de';

        $result = $this->parser->parseLine($line);

        self::assertNotNull($result);
        self::assertSame('216.73.217.0', $result->ip);
        self::assertSame('2026-08-23 23:59:57', $result->timestamp->format('Y-m-d H:i:s'));
        self::assertSame('+02:00', $result->timestamp->format('P'));
        self::assertSame('GET', $result->method);
        self::assertSame('/robots.txt', $result->target);
        self::assertSame('HTTP/1.1', $result->protocol);
        self::assertSame(200, $result->status);
        self::assertSame(5731, $result->bytesSent);
        self::assertNull($result->referer);
        self::assertStringContainsString('ClaudeBot/1.0', $result->userAgent);
        self::assertSame('www.move-elevator.de', $result->host);
    }

    #[Test]
    public function returnsNullForAnUnparseableLine(): void
    {
        self::assertNull($this->parser->parseLine('this is not a log line'));
    }

    #[Test]
    public function returnsNullForAnEmptyLine(): void
    {
        self::assertNull($this->parser->parseLine(''));
    }

    #[Test]
    public function treatsADashByteCountAsNull(): void
    {
        $line = '10.0.0.1 - - [01/Jan/2026:00:00:00 +0000] "GET / HTTP/1.1" 304 - "-" "Mozilla/5.0" example.test';

        $result = $this->parser->parseLine($line);

        self::assertNotNull($result);
        self::assertNull($result->bytesSent);
    }
}
