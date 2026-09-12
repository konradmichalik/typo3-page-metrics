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

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Parser\LogLine;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * LogLineTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(LogLine::class)]
final class LogLineTest extends TestCase
{
    #[Test]
    public function pathStripsQueryString(): void
    {
        $line = new LogLine(
            ip: '216.73.217.0',
            timestamp: new DateTimeImmutable('2026-08-23 23:59:57'),
            method: 'GET',
            target: '/search?q=foo&page=2',
            protocol: 'HTTP/1.1',
            status: 200,
            bytesSent: 5731,
            referer: null,
            userAgent: 'ClaudeBot/1.0',
            host: 'www.move-elevator.de',
        );

        self::assertSame('/search', $line->path());
    }

    #[Test]
    public function pathReturnsTargetUnchangedWhenNoQueryString(): void
    {
        $line = new LogLine(
            ip: '216.73.217.0',
            timestamp: new DateTimeImmutable('2026-08-23 23:59:57'),
            method: 'GET',
            target: '/robots.txt',
            protocol: 'HTTP/1.1',
            status: 200,
            bytesSent: 5731,
            referer: null,
            userAgent: 'ClaudeBot/1.0',
            host: 'www.move-elevator.de',
        );

        self::assertSame('/robots.txt', $line->path());
    }
}
