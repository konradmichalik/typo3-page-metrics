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

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, UrlMetric};
use KonradMichalik\Typo3PageMetrics\Provider\AccessLog\{AccessLogConfiguration, AccessLogConfigurationResolver, AccessLogLineParser, AccessLogMetricProvider, BotFilter};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;

use function array_map;
use function file_exists;
use function file_put_contents;
use function gzencode;
use function implode;
use function iterator_to_array;
use function mkdir;
use function rmdir;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * AccessLogMetricProviderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AccessLogMetricProviderTest extends TestCase
{
    /** @var AccessLogConfigurationResolver&\PHPUnit\Framework\MockObject\MockObject */
    private AccessLogConfigurationResolver $resolver;
    private AccessLogMetricProvider $subject;
    private Site $site;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(AccessLogConfigurationResolver::class);
        $this->subject = new AccessLogMetricProvider($this->resolver, new AccessLogLineParser(), new BotFilter());
        $this->site = new Site('main', 1, ['base' => 'https://example.com/']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testGetIdentifierReturnsAccesslog(): void
    {
        self::assertSame('accesslog', $this->subject->getIdentifier());
    }

    public function testGetMetricDefinitionsReturnsPageviewsAndVisits(): void
    {
        $identifiers = array_map(
            static fn ($definition) => $definition->identifier,
            $this->subject->getMetricDefinitions(),
        );

        self::assertSame(['accesslog.pageviews', 'accesslog.visits'], $identifiers);
    }

    public function testGetRateMetricDefinitionsReturnsNoRateMetrics(): void
    {
        self::assertSame([], $this->subject->getRateMetricDefinitions());
    }

    public function testGetExternalLinkReturnsNull(): void
    {
        $range = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

        self::assertNull($this->subject->getExternalLink($this->site, 'https://example.com/about', $range));
    }

    public function testSupportsReturnsTrueWhenConfigured(): void
    {
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration('/var/log/access.log*'));

        self::assertTrue($this->subject->supports($this->site));
    }

    public function testSupportsReturnsFalseWhenNotConfigured(): void
    {
        $this->resolver->method('resolve')->willReturn(null);

        self::assertFalse($this->subject->supports($this->site));
    }

    public function testFetchYieldsNothingWhenNotConfigured(): void
    {
        $this->resolver->method('resolve')->willReturn(null);

        $result = iterator_to_array($this->subject->fetch($this->site, $this->rangeCoveringOctober10th()));

        self::assertSame([], $result);
    }

    public function testFetchCountsPageviewsAndApproximatesVisitsByIpAndUserAgent(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
            $this->logLine('10/Oct/2026:11:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
            $this->logLine('10/Oct/2026:12:00:00 +0000', '203.0.113.2', 'GET', '/about', 200, 'BrowserB'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(3, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
        self::assertSame(2, $metrics['accesslog.visits']['https://example.com/about'] ?? null);
    }

    public function testFetchFiltersOutKnownBotUserAgents(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'Mozilla/5.0 (compatible; Googlebot/2.1)'),
            $this->logLine('10/Oct/2026:10:01:00 +0000', '203.0.113.2', 'GET', '/about', 200, 'Mozilla/5.0 real browser'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(1, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
    }

    public function testFetchOnlyCountsSuccessfulGetRequests(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:01:00 +0000', '203.0.113.2', 'GET', '/about', 404, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:02:00 +0000', '203.0.113.3', 'GET', '/about', 500, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:03:00 +0000', '203.0.113.4', 'GET', '/about', 301, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:04:00 +0000', '203.0.113.5', 'POST', '/about', 200, 'BrowserA'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(1, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
    }

    public function testFetchCountsA304NotModifiedAsAPageview(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:01:00 +0000', '203.0.113.2', 'GET', '/about', 304, 'BrowserA'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(2, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
    }

    public function testFetchFiltersOutStaticAssetsAndBackendPaths(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/style.css', 200, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:01:00 +0000', '203.0.113.1', 'GET', '/typo3/index.php', 200, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:02:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertArrayNotHasKey('https://example.com/style.css', $metrics['accesslog.pageviews'] ?? []);
        self::assertArrayNotHasKey('https://example.com/typo3/index.php', $metrics['accesslog.pageviews'] ?? []);
        self::assertSame(1, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
    }

    public function testFetchOnlyIncludesDatesWithinTheRequestedRange(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('09/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
            $this->logLine('11/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $result = iterator_to_array($this->subject->fetch($this->site, $this->rangeCoveringOctober10th()), false);
        $dates = array_map(static fn (UrlMetric $metric): string => $metric->date->format('Y-m-d'), $result);

        self::assertSame(['2026-10-10', '2026-10-10'], $dates);
    }

    public function testFetchSupportsAGzippedLogFile(): void
    {
        $line = $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA');
        $file = tempnam(sys_get_temp_dir(), 'accesslog-').'.gz';
        $this->tempFiles[] = $file;
        file_put_contents($file, gzencode($line."\n"));
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(1, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
    }

    public function testFetchSupportsAGlobPatternMatchingMultipleFiles(): void
    {
        $directory = (string) tempnam(sys_get_temp_dir(), 'accesslog-glob-');
        unlink($directory);
        mkdir($directory);
        file_put_contents($directory.'/access.log', $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA')."\n");
        file_put_contents($directory.'/access.log.1', $this->logLine('10/Oct/2026:11:00:00 +0000', '203.0.113.2', 'GET', '/about', 200, 'BrowserB')."\n");
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($directory.'/access.log*'));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(2, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);

        unlink($directory.'/access.log');
        unlink($directory.'/access.log.1');
        rmdir($directory);
    }

    private function rangeCoveringOctober10th(): DateRange
    {
        return new DateRange(new DateTimeImmutable('2026-10-10'), new DateTimeImmutable('2026-10-10'));
    }

    /**
     * @param list<string> $lines
     */
    private function writeLogFile(array $lines): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'accesslog-');
        $this->tempFiles[] = $file;
        file_put_contents($file, implode("\n", $lines)."\n");

        return $file;
    }

    private function logLine(string $timestamp, string $ip, string $method, string $path, int $status, string $userAgent): string
    {
        return sprintf('%s - - [%s] "%s %s HTTP/1.1" %d 512 "-" "%s"', $ip, $timestamp, $method, $path, $status, $userAgent);
    }

    /**
     * @return array<string, array<string, int>> metric identifier => url => value
     */
    private function fetchByMetric(DateRange $range): array
    {
        $result = [];

        foreach ($this->subject->fetch($this->site, $range) as $metric) {
            $result[$metric->metric][$metric->url] = $metric->value;
        }

        return $result;
    }
}
