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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Command;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\Bucket;
use KonradMichalik\Typo3PageMetrics\Command\ImportCommand;
use KonradMichalik\Typo3PageMetrics\Configuration\SiteConfigurationReader;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange, UrlMetric};
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, ImportStateRepository, UnresolvedUrlRepository};
use KonradMichalik\Typo3PageMetrics\Provider\{AnnotationProviderInterface, MetricProviderInterface};
use KonradMichalik\Typo3PageMetrics\Resolver\Resolver;
use KonradMichalik\Typo3PageMetrics\Service\BotAgentDetailCollector;
use KonradMichalik\Typo3PageMetrics\Tests\Functional\Command\Fixtures\{BotAwareFailingMetricProvider, BotAwareStubMetricProvider, FailingOnDateDailyMetricsRepository, PartiallyFailingAnnotationProvider, PartiallyFailingMetricProvider, StubAnnotationProvider, StubMetricProvider, UnsupportedAnnotationProvider, UnsupportedMetricProvider};
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
    protected array $coreExtensionsToLoad = ['redirects'];
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private DailyMetricsRepository $dailyMetricsRepository;
    private UnresolvedUrlRepository $unresolvedUrlRepository;
    private ImportStateRepository $importStateRepository;
    private AnnotationRepository $annotationRepository;

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
        $this->annotationRepository = $this->get(AnnotationRepository::class);
    }

    public function testImportsResolvedMetricsIntoTheDailyTable(): void
    {
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([new UrlMetric('https://example.com/about', $today, 'stub.hits', 42)]);

        $exitCode = $this->runImport([$stub]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $series = $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', new DateRange($today, $today));
        self::assertSame([$today->format('Y-m-d') => 42], $series);
    }

    public function testRecordsUnresolvedUrlsSeparately(): void
    {
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([new UrlMetric('https://example.com/gone-page', $today, 'stub.hits', 7)]);

        $this->runImport([$stub]);

        $count = (int) $this->get(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_typo3pagemetrics_unresolved')
            ->count('uid')
            ->from('tx_typo3pagemetrics_unresolved')
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
        $firstStub = new StubMetricProvider([new UrlMetric('https://example.com/about', $today, 'stub.hits', 10)]);
        $this->runImport([$firstStub]);

        $secondStub = new StubMetricProvider([new UrlMetric('https://example.com/about', $today, 'stub.hits', 999)]);
        $this->runImport([$secondStub]);

        $series = $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', new DateRange($today, $today));
        self::assertSame([$today->format('Y-m-d') => 999], $series);
    }

    public function testFirstRunMarksYesterdayNotTodayAsTheLastImportedDate(): void
    {
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([new UrlMetric('https://example.com/about', $today, 'stub.hits', 10)]);
        $this->runImport([$stub]);

        $lastImported = $this->importStateRepository->getLastImportedDate('stub', 'main');

        self::assertSame($today->modify('-1 day')->format('Y-m-d'), $lastImported?->format('Y-m-d'));
    }

    public function testTheCoveredWindowStartsWhereTheDataDoesNotWhereTheRequestDid(): void
    {
        // retentionDays defaults to 400, so the run asks for well over a
        // year. The provider only has the last few days, and claiming an
        // answer for the 390-odd days before that would draw them as a flat
        // zero on any 90-day chart.
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([
            new UrlMetric('https://example.com/about', $today->modify('-3 days'), 'stub.hits', 4),
            new UrlMetric('https://example.com/about', $today, 'stub.hits', 7),
        ]);

        $this->runImport([$stub]);

        $coverage = $this->importStateRepository->getCoverage('stub', 'main');

        self::assertNotNull($coverage);
        self::assertSame($today->modify('-3 days')->format('Y-m-d'), $coverage->from->format('Y-m-d'));
        self::assertSame($today->format('Y-m-d'), $coverage->until->format('Y-m-d'));
    }

    public function testAProviderThatReturnsNothingEstablishesNoCoveredWindow(): void
    {
        // An empty answer and an empty history are indistinguishable here,
        // and an unknown window masks nothing rather than blanking a chart.
        $this->runImport([new StubMetricProvider([])]);

        self::assertNull($this->importStateRepository->getCoverage('stub', 'main'));
        // The resume cursor still advances, so the import is unaffected.
        self::assertNotNull($this->importStateRepository->getLastImportedDate('stub', 'main'));
    }

    public function testAnEmptyRunLeavesAnAlreadyKnownWindowIntact(): void
    {
        $today = new DateTimeImmutable('today');
        $this->runImport([new StubMetricProvider([new UrlMetric('https://example.com/about', $today->modify('-5 days'), 'stub.hits', 3)])]);

        $this->runImport([new StubMetricProvider([])], true);

        self::assertSame(
            $today->modify('-5 days')->format('Y-m-d'),
            $this->importStateRepository->getCoverage('stub', 'main')?->from->format('Y-m-d'),
        );
    }

    public function testForceReimportsEvenWhenAlreadyImportedToday(): void
    {
        $today = new DateTimeImmutable('today');
        $firstStub = new StubMetricProvider([new UrlMetric('https://example.com/about', $today, 'stub.hits', 10)]);
        $this->runImport([$firstStub]);

        $secondStub = new StubMetricProvider([new UrlMetric('https://example.com/about', $today, 'stub.hits', 999)]);
        $this->runImport([$secondStub], true);

        $series = $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', new DateRange($today, $today));
        self::assertSame([$today->format('Y-m-d') => 999], $series);
    }

    public function testTwoUrlsResolvingToTheSamePageAndDaySumInsteadOfOverwriting(): void
    {
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([
            new UrlMetric('https://example.com/about', $today, 'stub.hits', 10),
            new UrlMetric('https://example.com/about?utm_source=x', $today, 'stub.hits', 7),
        ]);

        $this->runImport([$stub]);

        $series = $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', new DateRange($today, $today));
        self::assertSame([$today->format('Y-m-d') => 17], $series);
    }

    public function testResolvingThroughARedirectAlsoWritesAViaRedirectDimensionRow(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_redirect.csv');
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([new UrlMetric('https://example.com/old-about', $today, 'stub.hits', 15)]);

        $this->runImport([$stub]);

        $plain = $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', new DateRange($today, $today));
        $viaRedirect = $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', new DateRange($today, $today), 'via_redirect');
        self::assertSame([$today->format('Y-m-d') => 15], $plain);
        self::assertSame([$today->format('Y-m-d') => 15], $viaRedirect);
    }

    public function testResolvingDirectlyDoesNotWriteAViaRedirectDimensionRow(): void
    {
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([new UrlMetric('https://example.com/about', $today, 'stub.hits', 10)]);

        $this->runImport([$stub]);

        $viaRedirect = $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', new DateRange($today, $today), 'via_redirect');
        self::assertSame([$today->format('Y-m-d') => 0], $viaRedirect);
    }

    public function testADimensionedMetricIsNeverCountedIntoTheHeadlineTotal(): void
    {
        // The whole reason the channel split needs no schema change: the
        // aggregate is keyed by dimension, so a row that names one lands
        // beside the headline instead of in it. The headline stays the total
        // the tile shows, and the parts stay parts.
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([
            new UrlMetric('https://example.com/about', $today, 'stub.hits', 10),
            new UrlMetric('https://example.com/about', $today, 'stub.hits', 6, 'channel:search'),
            new UrlMetric('https://example.com/about', $today, 'stub.hits', 4, 'channel:direct'),
        ]);

        $this->runImport([$stub]);

        $range = new DateRange($today, $today);
        self::assertSame([$today->format('Y-m-d') => 10], $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', $range));
        self::assertSame([$today->format('Y-m-d') => 6], $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', $range, 'channel:search'));
        self::assertSame([$today->format('Y-m-d') => 4], $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', $range, 'channel:direct'));
    }

    public function testADimensionedMetricDoesNotInflateTheViaRedirectShare(): void
    {
        // via_redirect is a share of the headline, so it may only ever be
        // fed by the headline. Deriving it from a dimensioned row as well
        // would count the same visits twice and let the share exceed 100%.
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_redirect.csv');
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([
            new UrlMetric('https://example.com/old-about', $today, 'stub.hits', 15),
            new UrlMetric('https://example.com/old-about', $today, 'stub.hits', 5, 'channel:search'),
        ]);

        $this->runImport([$stub]);

        $range = new DateRange($today, $today);
        self::assertSame([$today->format('Y-m-d') => 15], $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', $range, 'via_redirect'));
    }

    public function testAnUnresolvedUrlIsRecordedFromItsHeadlineRowOnly(): void
    {
        // record() is idempotent on (provider, date, url_hash, metric), and a
        // dimensioned row carries the same four: it would overwrite the
        // total the headline recorded with a part of it and say nothing new.
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([
            new UrlMetric('https://example.com/gone-page', $today, 'stub.hits', 7),
            new UrlMetric('https://example.com/gone-page', $today, 'stub.hits', 3, 'channel:search'),
        ]);

        $this->runImport([$stub]);

        $rows = $this->get(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_typo3pagemetrics_unresolved')
            ->select('value')
            ->from('tx_typo3pagemetrics_unresolved')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(1, $rows);
        self::assertSame(7, (int) $rows[0]['value']);
    }

    public function testOneSitesProviderFailureDoesNotAbortImportForOtherSites(): void
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
        $provider = new PartiallyFailingMetricProvider('other', [new UrlMetric('https://example.com/about', $today, 'partial.hits', 5)]);

        $exitCode = $this->runImport([$provider]);

        self::assertSame(Command::FAILURE, $exitCode);
        $series = $this->dailyMetricsRepository->dailySeries(2, 0, 'partial.hits', new DateRange($today, $today));
        self::assertSame([$today->format('Y-m-d') => 5], $series);
    }

    public function testImportsAnnotationsForTheSiteRegardlessOfMetricProviders(): void
    {
        $annotation = new Annotation('1', new DateTimeImmutable('today'), 'Release 2.0');
        $annotationProvider = new StubAnnotationProvider([$annotation]);

        $exitCode = $this->runImport([], false, [$annotationProvider]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $stored = $this->annotationRepository->forSiteAndRange('main', new DateRange(
            (new DateTimeImmutable('today'))->modify('-5 days'),
            new DateTimeImmutable('today'),
        ));
        self::assertCount(1, $stored);
        self::assertSame('Release 2.0', $stored[0]->text);
    }

    public function testAFailingAnnotationProviderDoesNotAbortMetricImportForTheSameSite(): void
    {
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([new UrlMetric('https://example.com/about', $today, 'stub.hits', 10)]);

        $exitCode = $this->runImport([$stub], false, [new PartiallyFailingAnnotationProvider()]);

        self::assertSame(Command::FAILURE, $exitCode);
        $series = $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', new DateRange($today, $today));
        self::assertSame([$today->format('Y-m-d') => 10], $series);
    }

    public function testSkipsSitesThatHaveNotOptedIn(): void
    {
        $this->writeDisabledSite();
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([new UrlMetric('https://disabled.example.com/about', $today, 'stub.hits', 42)]);

        $exitCode = $this->runImport([$stub]);

        self::assertSame(Command::SUCCESS, $exitCode);
        // Only "main" was processed, so the opted-out site left no import state.
        self::assertNull($this->importStateRepository->getLastImportedDate('stub', 'disabled'));
        self::assertNotNull($this->importStateRepository->getLastImportedDate('stub', 'main'));
    }

    public function testSkipsProvidersThatDoNotSupportTheSite(): void
    {
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([new UrlMetric('https://example.com/about', $today, 'stub.hits', 42)]);

        $exitCode = $this->runImport([new UnsupportedMetricProvider(), $stub]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertNull($this->importStateRepository->getLastImportedDate('unsupported', 'main'));
        self::assertNotNull($this->importStateRepository->getLastImportedDate('stub', 'main'));
    }

    public function testSkipsAnnotationProvidersThatDoNotSupportTheSite(): void
    {
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([new UrlMetric('https://example.com/about', $today, 'stub.hits', 42)]);

        $exitCode = $this->runImport([$stub], false, [new UnsupportedAnnotationProvider()]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([], $this->annotationRepository->forSiteAndRange('main', new DateRange($today->modify('-5 days'), $today)));
    }

    public function testImportsNothingWhenEveryDayUpToTodayIsAlreadyImported(): void
    {
        $today = new DateTimeImmutable('today');
        $this->importStateRepository->markImported('stub', 'main', $today, new DateRange($today->modify('-30 days'), $today), true);
        $stub = new StubMetricProvider([new UrlMetric('https://example.com/about', $today, 'stub.hits', 42)]);

        $exitCode = $this->runImport([$stub]);

        self::assertSame(Command::SUCCESS, $exitCode);
        // dailySeries() zero-fills the range, so "nothing imported" is a 0, not an absent key.
        self::assertSame([$today->format('Y-m-d') => 0], $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', new DateRange($today, $today)));
    }

    public function testFlushesBotAgentDetailForABotAwareProviderAfterItsPersistenceStep(): void
    {
        $today = new DateTimeImmutable('today');
        $stub = new BotAwareStubMetricProvider(
            [new UrlMetric('https://example.com/about', $today, 'bot.ai_crawler', 3)],
            $this->get(BotAgentDetailCollector::class),
            $today,
            'https://example.com/about',
            Bucket::AiCrawler,
            'GPTBot',
        );

        $exitCode = $this->runImport([$stub]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_typo3pagemetrics_bot_agent_daily');
        $hits = $queryBuilder
            ->select('hits')
            ->from('tx_typo3pagemetrics_bot_agent_daily')
            ->where(
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter(2)),
                $queryBuilder->expr()->eq('bucket', $queryBuilder->createNamedParameter('ai_crawler')),
                $queryBuilder->expr()->eq('agent', $queryBuilder->createNamedParameter('GPTBot')),
            )
            ->executeQuery()
            ->fetchOne();

        self::assertSame(1, $hits);
    }

    public function testRunningTheSameLogImportTwiceProducesIdenticalTotalsRatherThanDoubleCounting(): void
    {
        $today = new DateTimeImmutable('today');
        $urlMetric = new UrlMetric('https://example.com/about', $today, 'bot.ai_crawler', 3);
        $collector = $this->get(BotAgentDetailCollector::class);

        $firstRun = new BotAwareStubMetricProvider([$urlMetric], $collector, $today, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot');
        $this->runImport([$firstRun], true);

        $secondRun = new BotAwareStubMetricProvider([$urlMetric], $collector, $today, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot');
        $this->runImport([$secondRun], true);

        $series = $this->dailyMetricsRepository->dailySeries(2, 0, 'bot.ai_crawler', new DateRange($today, $today));
        self::assertSame([$today->format('Y-m-d') => 3], $series);

        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_typo3pagemetrics_bot_agent_daily');
        $hits = $queryBuilder
            ->select('hits')
            ->from('tx_typo3pagemetrics_bot_agent_daily')
            ->where(
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter(2)),
                $queryBuilder->expr()->eq('bucket', $queryBuilder->createNamedParameter('ai_crawler')),
                $queryBuilder->expr()->eq('agent', $queryBuilder->createNamedParameter('GPTBot')),
            )
            ->executeQuery()
            ->fetchOne();

        // The collector counts one hit per add() call, not the unrelated
        // stub UrlMetric's value (3, asserted above for the daily-metrics
        // table only) - re-running the same single classified hit must
        // still land on 1, not 2, proving replace-not-add semantics for the
        // bot-agent-daily table too.
        self::assertSame(1, $hits);
    }

    public function testAProviderThrowingMidFetchLeavesNoBotAgentDetailBehind(): void
    {
        $today = new DateTimeImmutable('today');
        $collector = $this->get(BotAgentDetailCollector::class);
        $failing = new BotAwareFailingMetricProvider($collector, $today, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot');

        // The clean provider runs after the failing one and flushes the very
        // same shared collector: without the catch block's reset(), the
        // failing provider's partial hit would be written out here, under the
        // wrong provider's run.
        $clean = new StubMetricProvider([new UrlMetric('https://example.com/about', $today, 'stub.hits', 10)]);

        $exitCode = $this->runImport([$failing, $clean]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(0, $this->countBotAgentRows());
        // The clean provider itself still imported normally.
        self::assertSame([$today->format('Y-m-d') => 10], $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', new DateRange($today, $today)));
    }

    public function testAProviderThrowingMidFetchLeavesNoBotAgentDetailForTheNextSiteEither(): void
    {
        $this->writeSecondSite();
        $today = new DateTimeImmutable('today');
        $collector = $this->get(BotAgentDetailCollector::class);
        // The hit URL belongs to "main": were it carried into the "other"
        // site's loop iteration, flush() would attribute it to "other".
        $failing = new BotAwareFailingMetricProvider($collector, $today, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot');
        $clean = new StubMetricProvider([new UrlMetric('https://example.com/about', $today, 'stub.hits', 10)]);

        $exitCode = $this->runImport([$failing, $clean]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(0, $this->countBotAgentRows());
    }

    public function testAFailedWriteKeepsTheDaysCommittedBeforeItAndResumesAtTheLastOfThem(): void
    {
        // The import commits one transaction per day, rows and state
        // together, so a write that dies on the final day must leave the
        // earlier days durably imported with the cursor pointing at the last
        // one that actually committed - never past it.
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([
            new UrlMetric('https://example.com/about', $today->modify('-3 days'), 'stub.hits', 4),
            new UrlMetric('https://example.com/about', $today, 'stub.hits', 7),
        ]);

        $exitCode = $this->runImport([$stub], false, [], new FailingOnDateDailyMetricsRepository($this->get(ConnectionPool::class), $today));

        self::assertSame(Command::FAILURE, $exitCode);
        $series = $this->dailyMetricsRepository->dailySeries(2, 0, 'stub.hits', new DateRange($today->modify('-3 days'), $today));
        self::assertSame(4, $series[$today->modify('-3 days')->format('Y-m-d')]);
        self::assertSame(0, $series[$today->format('Y-m-d')]);
        self::assertSame(
            $today->modify('-3 days')->format('Y-m-d'),
            $this->importStateRepository->getLastImportedDate('stub', 'main')?->format('Y-m-d'),
        );
    }

    public function testAFailedFirstImportCoversOnlyTheDaysItManagedToWrite(): void
    {
        // Without a coverage window ImportCoverage::mask() withholds nothing,
        // so the days this run never reached would be drawn as genuine zeros
        // rather than as gaps. The window has to describe what committed, not
        // what was asked for.
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([
            new UrlMetric('https://example.com/about', $today->modify('-3 days'), 'stub.hits', 4),
            new UrlMetric('https://example.com/about', $today, 'stub.hits', 7),
        ]);

        $this->runImport([$stub], false, [], new FailingOnDateDailyMetricsRepository($this->get(ConnectionPool::class), $today));

        $coverage = $this->importStateRepository->getCoverage('stub', 'main');
        self::assertNotNull($coverage);
        self::assertSame($today->modify('-3 days')->format('Y-m-d'), $coverage->from->format('Y-m-d'));
        self::assertSame($today->modify('-3 days')->format('Y-m-d'), $coverage->until->format('Y-m-d'));
    }

    public function testAFailedWriteDoesNotMoveTheCursorPastTheDaysItWrote(): void
    {
        $today = new DateTimeImmutable('today');
        $this->importStateRepository->markImported('stub', 'main', $today->modify('-4 days'), null, true);
        $stub = new StubMetricProvider([
            new UrlMetric('https://example.com/about', $today->modify('-3 days'), 'stub.hits', 4),
            new UrlMetric('https://example.com/about', $today, 'stub.hits', 7),
        ]);

        $this->runImport([$stub], false, [], new FailingOnDateDailyMetricsRepository($this->get(ConnectionPool::class), $today));

        self::assertSame(
            $today->modify('-3 days')->format('Y-m-d'),
            $this->importStateRepository->getLastImportedDate('stub', 'main')?->format('Y-m-d'),
        );
    }

    public function testAForcedRunNeverMovesTheCursorBackwards(): void
    {
        // --force re-fetches the whole retention window, so its oldest day
        // commits first. Writing that day's date as the cursor would park it
        // a retention window in the past, and a run that then aborts would
        // leave every following scheduled run re-fetching everything from the
        // provider's API until one completes.
        $today = new DateTimeImmutable('today');
        $this->importStateRepository->markImported('stub', 'main', $today->modify('-1 day'), null, true);
        $stub = new StubMetricProvider([
            new UrlMetric('https://example.com/about', $today->modify('-30 days'), 'stub.hits', 4),
            new UrlMetric('https://example.com/about', $today, 'stub.hits', 7),
        ]);

        $this->runImport([$stub], true, [], new FailingOnDateDailyMetricsRepository($this->get(ConnectionPool::class), $today));

        self::assertSame(
            $today->modify('-1 day')->format('Y-m-d'),
            $this->importStateRepository->getLastImportedDate('stub', 'main')?->format('Y-m-d'),
        );
    }

    public function testUnresolvedUrlsSeenByAFailedRunAreKept(): void
    {
        // Deliberate: unresolved rows are diagnostic, are written outside
        // the per-day transactions, and survive a run whose write phase
        // failed, which is exactly the run whose trail is worth keeping. See
        // the comment at the record() call site in ImportCommand.
        $today = new DateTimeImmutable('today');
        $stub = new StubMetricProvider([
            new UrlMetric('https://example.com/gone-page', $today->modify('-3 days'), 'stub.hits', 7),
            new UrlMetric('https://example.com/about', $today, 'stub.hits', 7),
        ]);

        $this->runImport([$stub], false, [], new FailingOnDateDailyMetricsRepository($this->get(ConnectionPool::class), $today));

        $count = (int) $this->get(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_typo3pagemetrics_unresolved')
            ->count('uid')
            ->from('tx_typo3pagemetrics_unresolved')
            ->executeQuery()
            ->fetchOne();

        self::assertSame(1, $count);
    }

    private function countBotAgentRows(): int
    {
        return (int) $this->get(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_typo3pagemetrics_bot_agent_daily')
            ->count('uid')
            ->from('tx_typo3pagemetrics_bot_agent_daily')
            ->executeQuery()
            ->fetchOne();
    }

    private function writeSecondSite(): void
    {
        $this->get(SiteWriter::class)->write('other', [
            'rootPageId' => 10,
            'base' => 'https://other.example.com/',
            'languages' => [
                0 => ['title' => 'English', 'enabled' => true, 'languageId' => 0, 'base' => '/', 'locale' => 'en_US.UTF-8', 'navigationTitle' => 'English', 'flag' => 'us'],
            ],
            'errorHandling' => [],
            'routes' => [],
            'pageMetrics' => ['enabled' => true, 'retentionDays' => 5],
        ]);
    }

    private function writeDisabledSite(): void
    {
        $this->get(SiteWriter::class)->write('disabled', [
            'rootPageId' => 10,
            'base' => 'https://disabled.example.com/',
            'languages' => [
                0 => ['title' => 'English', 'enabled' => true, 'languageId' => 0, 'base' => '/', 'locale' => 'en_US.UTF-8', 'navigationTitle' => 'English', 'flag' => 'us'],
            ],
            'errorHandling' => [],
            'routes' => [],
            'pageMetrics' => ['enabled' => false],
        ]);
    }

    /**
     * @param list<MetricProviderInterface>     $providers
     * @param list<AnnotationProviderInterface> $annotationProviders
     */
    private function runImport(array $providers, bool $force = false, array $annotationProviders = [], ?DailyMetricsRepository $dailyMetricsRepository = null): int
    {
        $command = new ImportCommand(
            $this->get(SiteFinder::class),
            $this->get(SiteConfigurationReader::class),
            $this->get(Resolver::class),
            $dailyMetricsRepository ?? $this->dailyMetricsRepository,
            $this->unresolvedUrlRepository,
            $this->importStateRepository,
            $this->annotationRepository,
            $this->get(BotAgentDetailCollector::class),
            $this->get(ConnectionPool::class),
            $providers,
            $annotationProviders,
        );

        $tester = new CommandTester($command);
        $tester->execute($force ? ['--force' => true] : []);

        return $tester->getStatusCode();
    }
}
