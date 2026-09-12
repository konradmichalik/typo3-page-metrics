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
use KonradMichalik\Typo3PageMetrics\Analysis\Guard\PathHeuristicGuard;
use KonradMichalik\Typo3PageMetrics\Analysis\Parser\CombinedLogParser;
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\{AgentMatcher, BotTaxonomy};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, UrlMetric};
use KonradMichalik\Typo3PageMetrics\Import\LogFileReader;
use KonradMichalik\Typo3PageMetrics\Provider\AccessLog\{AccessLogConfiguration, AccessLogConfigurationResolver, AccessLogMetricProvider};
use KonradMichalik\Typo3PageMetrics\Service\BotAgentDetailCollector;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Site\Entity\Site;

use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
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
use function touch;
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

    /** @var BotAgentDetailCollector&\PHPUnit\Framework\MockObject\MockObject */
    private BotAgentDetailCollector $collector;

    private AccessLogMetricProvider $subject;
    private Site $site;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(AccessLogConfigurationResolver::class);
        $this->collector = $this->createMock(BotAgentDetailCollector::class);
        $this->subject = new AccessLogMetricProvider(
            $this->resolver,
            new CombinedLogParser(),
            new BotTaxonomy(new AgentMatcher()),
            new PathHeuristicGuard(),
            $this->collector,
            new LogFileReader(),
        );
        $this->site = new Site('main', 1, ['base' => 'https://example.com/']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    public function testGetIdentifierReturnsAccesslog(): void
    {
        self::assertSame('accesslog', $this->subject->getIdentifier());
    }

    public function testGetMetricDefinitionsReturnsPageviewsVisitsAndTheFiveBotBuckets(): void
    {
        $identifiers = array_map(
            static fn ($definition) => $definition->identifier,
            $this->subject->getMetricDefinitions(),
        );

        self::assertSame(
            ['accesslog.pageviews', 'accesslog.visits', 'bot.ai_chatbot', 'bot.ai_crawler', 'bot.search', 'bot.social', 'bot.tool'],
            $identifiers,
        );
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

    public function testFetchFiltersOutKnownBotUserAgentsFromHumanMetrics(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'Mozilla/5.0 (compatible; Googlebot/2.1)'),
            $this->logLine('10/Oct/2026:10:01:00 +0000', '203.0.113.2', 'GET', '/about', 200, 'Mozilla/5.0 real browser'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(1, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
    }

    public function testFetchExcludesUnnamedGenericBotsFromHumanMetricsWithoutBucketingThem(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'SomeVendorCrawler/1.0'),
            $this->logLine('10/Oct/2026:10:01:00 +0000', '203.0.113.2', 'GET', '/about', 200, 'WebSpider 3.2'),
            $this->logLine('10/Oct/2026:10:02:00 +0000', '203.0.113.3', 'GET', '/about', 200, 'ACME-Monitor/2.0'),
            $this->logLine('10/Oct/2026:10:03:00 +0000', '203.0.113.4', 'GET', '/about', 200, 'Mozilla/5.0 real browser'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(1, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
        self::assertSame(1, $metrics['accesslog.visits']['https://example.com/about'] ?? null);
        self::assertSame(['accesslog.pageviews', 'accesslog.visits'], array_keys($metrics));
    }

    public function testFetchExcludesAnEmptyUserAgentFromHumanMetricsWithoutBucketingIt(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, ''),
            $this->logLine('10/Oct/2026:10:01:00 +0000', '203.0.113.2', 'GET', '/about', 200, 'Mozilla/5.0 real browser'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(1, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
        self::assertSame(['accesslog.pageviews', 'accesslog.visits'], array_keys($metrics));
    }

    public function testFetchNeverTellsTheCollectorAboutAnUnnamedGenericBot(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'SomeVendorCrawler/1.0'),
            $this->logLine('10/Oct/2026:10:01:00 +0000', '203.0.113.2', 'GET', '/about', 200, ''),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));
        $this->collector->expects(self::never())->method('add');

        iterator_to_array($this->subject->fetch($this->site, $this->rangeCoveringOctober10th()));
    }

    public function testFetchOnlyCountsSuccessfulGetRequestsAsHumanPageviews(): void
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

    public function testFetchCountsA304NotModifiedAsAHumanPageview(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:01:00 +0000', '203.0.113.2', 'GET', '/about', 304, 'BrowserA'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(2, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
    }

    public function testFetchFiltersOutStaticAssetsAndBackendPathsFromHumanMetrics(): void
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

    public function testFetchReadsAGzippedLogFileThatHasNoGzExtension(): void
    {
        // mittwald and similar hosts rotate into extensionless gzip files.
        // Picking the reader by suffix fed compressed bytes to the parser,
        // every line failed to match, and the import reported success with
        // zero rows.
        $line = $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA');
        $file = (string) tempnam(sys_get_temp_dir(), 'accesslog-rotated-');
        $this->tempFiles[] = $file;
        file_put_contents($file, gzencode($line."\n"));
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(1, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
    }

    public function testFetchEmitsEachDayExactlyOnce(): void
    {
        // Days are emitted as the reader moves past them rather than all at
        // the end, so the flush and the final drain must not both emit one.
        $file = $this->writeLogFile([
            $this->logLine('08/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
            $this->logLine('09/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));
        $range = new DateRange(new DateTimeImmutable('2026-10-08'), new DateTimeImmutable('2026-10-10'));

        $seen = [];
        foreach ($this->subject->fetch($this->site, $range) as $metric) {
            $key = $metric->date->format('Y-m-d').'|'.$metric->metric.'|'.$metric->url;
            $seen[$key] = ($seen[$key] ?? 0) + 1;
        }

        self::assertNotEmpty($seen);
        self::assertSame([1], array_values(array_unique($seen)));
        self::assertCount(6, $seen);
    }

    public function testVisitsStayADistinctCountWhenOneDaySpansTwoFiles(): void
    {
        $directory = (string) tempnam(sys_get_temp_dir(), 'accesslog-split-');
        unlink($directory);
        mkdir($directory);
        $older = $directory.'/access.log.1';
        $newer = $directory.'/access.log';
        file_put_contents($older, $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA')."\n");
        file_put_contents($newer, $this->logLine('10/Oct/2026:11:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA')."\n");
        touch($older, 1_760_000_000);
        touch($newer, 1_760_090_000);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($directory.'/access.log*'));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(2, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
        // Same visitor on both sides of the rotation boundary, so one visit.
        self::assertSame(1, $metrics['accesslog.visits']['https://example.com/about'] ?? null);

        unlink($older);
        unlink($newer);
        rmdir($directory);
    }

    public function testFetchReadsRotatedFilesOldestFirst(): void
    {
        $directory = (string) tempnam(sys_get_temp_dir(), 'accesslog-order-');
        unlink($directory);
        mkdir($directory);
        // Named so an alphabetical sort would put them the wrong way round.
        $older = $directory.'/access.log.9';
        $newer = $directory.'/access.log.10';
        file_put_contents($older, $this->logLine('08/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA')."\n");
        file_put_contents($newer, $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.2', 'GET', '/about', 200, 'BrowserB')."\n");
        // Modification times deliberately the wrong way round, as a restore
        // from backup or a cp without -p leaves them: the order has to come
        // from what the files contain.
        touch($older, 1_760_200_000);
        touch($newer, 1_760_000_000);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($directory.'/access.log.*'));
        $range = new DateRange(new DateTimeImmutable('2026-10-08'), new DateTimeImmutable('2026-10-10'));

        $dates = [];
        foreach ($this->subject->fetch($this->site, $range) as $metric) {
            $dates[] = $metric->date->format('Y-m-d');
        }

        self::assertSame(['2026-10-08', '2026-10-08', '2026-10-10', '2026-10-10'], $dates);

        unlink($older);
        unlink($newer);
        rmdir($directory);
    }

    public function testFetchSkipsAnUnreadableFileInsteadOfLosingTheWholeImport(): void
    {
        // A glob over a rotation directory routinely picks up a file the web
        // user cannot read, and logrotate can remove one mid-run. Neither is
        // a reason to throw away every other file's data.
        $directory = (string) tempnam(sys_get_temp_dir(), 'accesslog-unreadable-');
        unlink($directory);
        mkdir($directory);
        $readable = $directory.'/access.log';
        $unreadable = $directory.'/access.log.1';
        file_put_contents($readable, $this->logLine('08/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA')."\n");
        file_put_contents($unreadable, $this->logLine('07/Oct/2026:10:00:00 +0000', '203.0.113.2', 'GET', '/about', 200, 'BrowserB')."\n");
        chmod($unreadable, 0o000);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($directory.'/access.log*'));
        $range = new DateRange(new DateTimeImmutable('2026-10-07'), new DateTimeImmutable('2026-10-08'));

        $dates = [];
        foreach ($this->subject->fetch($this->site, $range) as $metric) {
            $dates[] = $metric->date->format('Y-m-d');
        }

        self::assertSame(['2026-10-08', '2026-10-08'], $dates);

        chmod($unreadable, 0o644);
        unlink($readable);
        unlink($unreadable);
        rmdir($directory);
    }

    public function testFetchRefusesALogSetThatRevisitsAnAlreadyImportedDay(): void
    {
        // Two servers' logs behind one pattern, or any set that cannot be
        // ordered: the second pass would double-count every overlapping day
        // and put the whole window back into memory. Refusing names the
        // cause; finishing would produce numbers nobody can audit.
        $directory = (string) tempnam(sys_get_temp_dir(), 'accesslog-overlap-');
        unlink($directory);
        mkdir($directory);
        $first = $directory.'/a-access.log';
        $second = $directory.'/b-access.log';
        file_put_contents($first, implode("\n", [
            $this->logLine('01/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
            $this->logLine('05/Oct/2026:10:00:00 +0000', '203.0.113.2', 'GET', '/about', 200, 'BrowserB'),
        ])."\n");
        file_put_contents($second, $this->logLine('01/Oct/2026:11:00:00 +0000', '203.0.113.3', 'GET', '/about', 200, 'BrowserC')."\n");
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($directory.'/*-access.log'));
        $range = new DateRange(new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-10-05'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1757700001);

        try {
            foreach ($this->subject->fetch($this->site, $range) as $metric) {
                // Drain: the failure is only reachable once the second file
                // is read, which is after the first day has been emitted.
            }
        } finally {
            unlink($first);
            unlink($second);
            rmdir($directory);
        }
    }

    public function testFetchYieldsABotBucketMetricForAClassifiedLineRegardlessOfStatus(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/gone', 404, 'GPTBot/1.0'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(1, $metrics['bot.ai_crawler']['https://example.com/gone'] ?? null);
    }

    public function testFetchDoesNotCountAClassifiedLineAsAHumanPageview(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'GPTBot/1.0'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertArrayNotHasKey('https://example.com/about', $metrics['accesslog.pageviews'] ?? []);
    }

    public function testFetchTellsTheCollectorAboutEveryClassifiedLine(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'GPTBot/1.0'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));
        $this->collector->expects(self::once())->method('add');

        iterator_to_array($this->subject->fetch($this->site, $this->rangeCoveringOctober10th()));
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
