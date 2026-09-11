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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Analysis\Guard;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Guard\PathHeuristicGuard;
use KonradMichalik\Typo3PageMetrics\Analysis\Parser\LogLine;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;

/**
 * PathHeuristicGuardTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(PathHeuristicGuard::class)]
final class PathHeuristicGuardTest extends TestCase
{
    private PathHeuristicGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new PathHeuristicGuard();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function suspiciousPaths(): array
    {
        return [
            'dotenv' => ['/.env'],
            'ssh key' => ['/id_rsa'],
            'wp-login' => ['/wp-login.php'],
            'wp-admin' => ['/wp-admin/'],
            'phpmyadmin' => ['/phpmyadmin/index.php'],
            'sql backup' => ['/backup.sql'],
            'pem file' => ['/certs/server.pem'],
        ];
    }

    #[Test]
    #[DataProvider('suspiciousPaths')]
    public function flagsKnownCredentialAndAdminPaths(string $target): void
    {
        self::assertTrue($this->guard->isSuspicious($this->lineFor($target)));
    }

    #[Test]
    public function doesNotFlagARegularPage(): void
    {
        self::assertFalse($this->guard->isSuspicious($this->lineFor('/robots.txt')));
    }

    #[Test]
    public function doesNotFlagARealClaudeBotRobotsTxtRequest(): void
    {
        self::assertFalse($this->guard->isSuspicious($this->lineFor('/robots.txt')));
    }

    private function lineFor(string $target): LogLine
    {
        return new LogLine(
            ip: '203.0.113.9',
            timestamp: new DateTimeImmutable('2026-08-23 12:00:00'),
            method: 'GET',
            target: $target,
            protocol: 'HTTP/1.1',
            status: 404,
            bytesSent: 0,
            referer: null,
            userAgent: 'GPTBot/1.0',
            host: 'www.move-elevator.de',
        );
    }
}
