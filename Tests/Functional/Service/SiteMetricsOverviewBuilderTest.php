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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Service;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{TrendSentiment, UnresolvedReason};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange};
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, ImportStateRepository, UnresolvedUrlRepository};
use KonradMichalik\Typo3PageMetrics\Service\SiteMetricsOverviewBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SiteMetricsOverviewBuilderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SiteMetricsOverviewBuilderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    public function testHasProviderIsFalseWhenNoSiteConfigurationExists(): void
    {
        $this->writeSiteConfigurationWithoutProvider();
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $overview = $this->get(SiteMetricsOverviewBuilder::class)->build($site, 0, 30);

        self::assertFalse($overview->hasProvider);
        self::assertSame([], $overview->chartSeries);
        self::assertSame([], $overview->topPages);
    }

    public function testASiteThatOnlyCountsMachineTrafficIsAnsweredLikeOneWithNoProvider(): void
    {
        // The widgets lead with the site's primary metric, and crawler counts
        // are not one: a "most viewed pages" list ranked by what indexers
        // fetched answers a question nobody asked of a dashboard (see #88).
        $this->writeSiteConfiguration(['accesslog' => ['pathPattern' => '/var/log/nginx/access.log*', 'metrics' => ['machine']]]);
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'accesslog', new DateTimeImmutable('today'), 'bot.search', 10);
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $overview = $this->get(SiteMetricsOverviewBuilder::class)->build($site, 0, 7);

        self::assertFalse($overview->hasProvider);
        self::assertSame([], $overview->chartSeries);
    }

    public function testASiteThatDeclaresOnlyRatesIsAnsweredLikeOneWithNoProvider(): void
    {
        // A rate can never be primary: a chart whose area is a percentage
        // says nothing. The site opted into a provider all the same, so this
        // has to be an answer rather than a fatal.
        $this->writeSiteConfiguration(['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x', 'metrics' => ['matomo.bounce_rate']]]);
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $overview = $this->get(SiteMetricsOverviewBuilder::class)->build($site, 0, 7);

        self::assertFalse($overview->hasProvider);
        self::assertNull($this->get(SiteMetricsOverviewBuilder::class)->buildTopPagesForSite($site, 0, 7));
    }

    public function testChartSeriesSumsAcrossEveryPageInTheSite(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 5);
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $overview = $this->get(SiteMetricsOverviewBuilder::class)->build($site, 0, 7);

        self::assertTrue($overview->hasProvider);
        self::assertSame(15, $overview->chartSeries[$today->format('Y-m-d')]);
        self::assertSame('Page views', $overview->chartLabel);
    }

    public function testAvailableMetricsIncludesTheMachineSummaryWhereThereAreMachineMetrics(): void
    {
        $this->writeSiteConfiguration(['accesslog' => ['pathPattern' => '/var/log/nginx/access.log']]);
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $metrics = $this->get(SiteMetricsOverviewBuilder::class)->availableMetrics([$site]);

        self::assertArrayHasKey('machine.summary.accesslog', $metrics);
        self::assertSame('Bot traffic', $metrics['machine.summary.accesslog']);
    }

    public function testAvailableMetricsOmitsTheMachineSummaryWhereThereIsNothingToSum(): void
    {
        $this->writeSiteConfiguration();
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $metrics = $this->get(SiteMetricsOverviewBuilder::class)->availableMetrics([$site]);

        self::assertArrayNotHasKey('machine.summary.matomo', $metrics);
    }

    public function testBuildPlotsTheMachineSummaryAcrossEveryBotMetricWhenSelected(): void
    {
        $this->writeSiteConfiguration(['accesslog' => ['pathPattern' => '/var/log/nginx/access.log']]);
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'accesslog', $today, 'bot.ai_crawler', 40);
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'accesslog', $today, 'bot.search', 10);
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $overview = $this->get(SiteMetricsOverviewBuilder::class)->build($site, 0, 7, 'machine.summary.accesslog');

        self::assertSame('Bot traffic', $overview->chartLabel);
        self::assertSame(50, $overview->chartSeries[$today->format('Y-m-d')]);
    }

    public function testBuildFallsBackToThePrimaryMetricWhenTheMachineSummaryHasNothingToSum(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 42);
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $overview = $this->get(SiteMetricsOverviewBuilder::class)->build($site, 0, 7, 'machine.summary.matomo');

        self::assertSame('Page views', $overview->chartLabel);
    }

    public function testTopPagesAreRankedHighestFirstWithResolvedTitles(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 5);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 20);
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $overview = $this->get(SiteMetricsOverviewBuilder::class)->build($site, 0, 7);

        self::assertCount(2, $overview->topPages);
        self::assertSame(2, $overview->topPages[0]->pageUid);
        self::assertSame('About', $overview->topPages[0]->title);
        self::assertSame('20', $overview->topPages[0]->formattedValue);
        self::assertSame(1, $overview->topPages[1]->pageUid);
    }

    public function testTheTrendWidgetRanksNothingWhileThePreviousPeriodIsNotFullyImported(): void
    {
        // The import reaches four days into the week the widget compares
        // against, so that week's sum is short by three days nobody can
        // quantify and the page rises by exactly that much.
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today, new DateRange($today->modify('-9 days'), $today), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 400);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-8 days'), 'matomo.pageviews', 100);
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $risen = $this->get(SiteMetricsOverviewBuilder::class)->buildTrendPages($site, 0, 7, TrendSentiment::Positive);

        self::assertSame([], $risen);
    }

    public function testTheTrendWidgetRanksTheSameMovementOnceTheWholePreviousPeriodIsImported(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today, new DateRange($today->modify('-13 days'), $today), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 400);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-8 days'), 'matomo.pageviews', 100);
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $risen = $this->get(SiteMetricsOverviewBuilder::class)->buildTrendPages($site, 0, 7, TrendSentiment::Positive);

        self::assertNotNull($risen);
        self::assertCount(1, $risen);
        self::assertSame(2, $risen[0]->pageUid);
    }

    public function testMostViewedPagesStillRankWithoutTheComparisonTheImportCannotSupport(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today, new DateRange($today->modify('-6 days'), $today), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 20);
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $overview = $this->get(SiteMetricsOverviewBuilder::class)->build($site, 0, 7);

        self::assertCount(1, $overview->topPages);
        self::assertSame('20', $overview->topPages[0]->formattedValue);
        self::assertTrue($overview->topPages[0]->hasNoComparison);
    }

    public function testUnresolvedUrlsAreListedForTheSite(): void
    {
        $this->writeSiteConfiguration();
        $this->get(UnresolvedUrlRepository::class)->record('matomo', new DateTimeImmutable('today'), 'https://example.com/gone', 'matomo.pageviews', UnresolvedReason::NonPage, 3, 100);
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $overview = $this->get(SiteMetricsOverviewBuilder::class)->build($site, 0, 7);

        self::assertCount(1, $overview->unresolvedUrls);
        self::assertSame('https://example.com/gone', $overview->unresolvedUrls[0]->url);
        self::assertSame('/gone', $overview->unresolvedUrls[0]->path);
        self::assertSame(UnresolvedReason::NonPage, $overview->unresolvedUrls[0]->reason);
        self::assertSame(3, $overview->unresolvedUrls[0]->value);
    }

    public function testAnnotationsAreReturnedForTheSiteAndRange(): void
    {
        $this->writeSiteConfiguration();
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', new Annotation('a1', new DateTimeImmutable('today'), 'Release 2.0'));
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $overview = $this->get(SiteMetricsOverviewBuilder::class)->build($site, 0, 7);

        self::assertCount(1, $overview->annotations);
        self::assertSame('Release 2.0', $overview->annotations[0]->text);
    }

    public function testAnnotationsCombineImportedAndManuallyCreatedOnes(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', new Annotation('a1', $today, 'Imported release'));
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_typo3pagemetrics_manual_annotation')->insert(
            'tx_typo3pagemetrics_manual_annotation',
            ['pid' => 0, 'site_identifier' => 'main', 'date' => $today->getTimestamp(), 'text' => 'Manual note'],
        );
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $overview = $this->get(SiteMetricsOverviewBuilder::class)->build($site, 0, 7);

        $texts = array_map(static fn ($annotation) => $annotation->text, $overview->annotations);
        self::assertContains('Imported release', $texts);
        self::assertContains('Manual note', $texts);
    }

    /**
     * @param array<string, array<string, mixed>> $providers what the site opts into, the Matomo default being the one every test that only needs "a site with numbers" uses
     */
    private function writeSiteConfiguration(array $providers = ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']]): void
    {
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
            'pageMetrics' => ['providers' => $providers],
        ]);
    }

    private function writeSiteConfigurationWithoutProvider(): void
    {
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
        ]);
    }
}
