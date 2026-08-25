<?php

declare(strict_types=1);

/*
 * This file is part of the "page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Command;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Command\ImportCommand;
use KonradMichalik\Typo3PageMetrics\Configuration\SiteConfigurationReader;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, UrlMetric};
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, ImportStateRepository, UnresolvedUrlRepository};
use KonradMichalik\Typo3PageMetrics\Resolver\Resolver;
use KonradMichalik\Typo3PageMetrics\Source\MetricSourceInterface;
use KonradMichalik\Typo3PageMetrics\Tests\Functional\Command\Fixtures\{PartiallyFailingMetricSource, StubMetricSource};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * ImportCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ImportCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private DailyMetricsRepository $dailyMetricsRepository;
    private UnresolvedUrlRepository $unresolvedUrlRepository;
    private ImportStateRepository $importStateRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');

        // TYPO3 v14's testing-framework 9.x dropped SiteBasedTestTrait; the
        // core SiteWriter service is the direct replacement for building and
        // persisting a site configuration in functional tests.
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                0 => [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'navigationTitle' => 'English',
                    'flag' => 'us',
                ],
            ],
            'errorHandling' => [],
            'routes' => [],
            'pageMetrics' => ['enabled' => true, 'retentionDays' => 5],
        ]);

        $this->dailyMetricsRepository = $this->get(DailyMetricsRepository::class);
        $this->unresolvedUrlRepository = $this->get(UnresolvedUrlRepository::class);
        $this->importStateRepository = $this->get(ImportStateRepository::class);
    }

    public function testImportsResolvedMetricsIntoTheDailyTable(): void
    {
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricSource([new UrlMetric('https://example.com/about', $today, 'stub.hits', 42)]);

        $exitCode = $this->runImport([$stub]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $series = $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', new DateRange($today, $today));
        self::assertSame([$today->format('Y-m-d') => 42], $series);
    }

    public function testRecordsUnresolvedUrlsSeparately(): void
    {
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricSource([new UrlMetric('https://example.com/gone-page', $today, 'stub.hits', 7)]);

        $this->runImport([$stub]);

        $count = (int) $this->get(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_pagemetrics_unresolved')
            ->count('uid')
            ->from('tx_pagemetrics_unresolved')
            ->executeQuery()
            ->fetchOne();

        self::assertSame(1, $count);
    }

    public function testSecondRunOnTheSameDayReprocessesTodaysPartialData(): void
    {
        // Today is only ever marked as "last complete day minus one" (see
        // testFirstRunMarksYesterdayNotTodayAsTheLastImportedDate below), so
        // a same-day rerun must revisit today rather than freezing it at
        // whatever partial value the first (e.g. early-morning) run saw.
        $today = new DateTimeImmutable('today');
        $firstStub = new StubMetricSource([new UrlMetric('https://example.com/about', $today, 'stub.hits', 10)]);
        $this->runImport([$firstStub]);

        $secondStub = new StubMetricSource([new UrlMetric('https://example.com/about', $today, 'stub.hits', 999)]);
        $this->runImport([$secondStub]);

        $series = $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', new DateRange($today, $today));
        self::assertSame([$today->format('Y-m-d') => 999], $series);
    }

    public function testFirstRunMarksYesterdayNotTodayAsTheLastImportedDate(): void
    {
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricSource([new UrlMetric('https://example.com/about', $today, 'stub.hits', 10)]);
        $this->runImport([$stub]);

        $lastImported = $this->importStateRepository->getLastImportedDate('stub', 'main');

        self::assertSame($today->modify('-1 day')->format('Y-m-d'), $lastImported?->format('Y-m-d'));
    }

    public function testForceReimportsEvenWhenAlreadyImportedToday(): void
    {
        $today = new DateTimeImmutable('today');
        $firstStub = new StubMetricSource([new UrlMetric('https://example.com/about', $today, 'stub.hits', 10)]);
        $this->runImport([$firstStub]);

        $secondStub = new StubMetricSource([new UrlMetric('https://example.com/about', $today, 'stub.hits', 999)]);
        $this->runImport([$secondStub], true);

        $series = $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', new DateRange($today, $today));
        self::assertSame([$today->format('Y-m-d') => 999], $series);
    }

    public function testOneSitesSourceFailureDoesNotAbortImportForOtherSites(): void
    {
        $this->get(SiteWriter::class)->write('other', [
            'rootPageId' => 10,
            'base' => 'https://other.example.com/',
            'languages' => [
                0 => [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'navigationTitle' => 'English',
                    'flag' => 'us',
                ],
            ],
            'errorHandling' => [],
            'routes' => [],
            'pageMetrics' => ['enabled' => true, 'retentionDays' => 5],
        ]);

        $today = new DateTimeImmutable('today');
        $source = new PartiallyFailingMetricSource('other', [new UrlMetric('https://example.com/about', $today, 'partial.hits', 5)]);

        $exitCode = $this->runImport([$source]);

        self::assertSame(Command::FAILURE, $exitCode);
        $series = $this->dailyMetricsRepository->dailySeries(2, 0, 'partial.hits', new DateRange($today, $today));
        self::assertSame([$today->format('Y-m-d') => 5], $series);
    }

    /**
     * @param list<MetricSourceInterface> $sources
     */
    private function runImport(array $sources, bool $force = false): int
    {
        $command = new ImportCommand(
            $this->get(SiteFinder::class),
            $this->get(SiteConfigurationReader::class),
            $this->get(Resolver::class),
            $this->dailyMetricsRepository,
            $this->unresolvedUrlRepository,
            $this->importStateRepository,
            $sources,
        );

        $tester = new CommandTester($command);
        $tester->execute($force ? ['--force' => true] : []);

        return $tester->getStatusCode();
    }
}
