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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Service;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\UnresolvedReason;
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, UnresolvedUrlRepository};
use KonradMichalik\Typo3PageMetrics\Service\SiteMetricsOverviewBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
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
        $this->setUpBackendUser(1);
    }

    public function testHasSourceIsFalseWhenNoSiteConfigurationExists(): void
    {
        $this->writeSiteConfigurationWithoutSource();
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $overview = $this->get(SiteMetricsOverviewBuilder::class)->build($site, 0, 30);

        self::assertFalse($overview->hasSource);
        self::assertSame([], $overview->chartSeries);
        self::assertSame([], $overview->topPages);
    }

    public function testChartSeriesSumsAcrossEveryPageInTheSite(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 5);
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $overview = $this->get(SiteMetricsOverviewBuilder::class)->build($site, 0, 7);

        self::assertTrue($overview->hasSource);
        self::assertSame(15, $overview->chartSeries[$today->format('Y-m-d')]);
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
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', 'a1', new DateTimeImmutable('today'), 'Release 2.0');
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);

        $overview = $this->get(SiteMetricsOverviewBuilder::class)->build($site, 0, 7);

        self::assertCount(1, $overview->annotations);
        self::assertSame('Release 2.0', $overview->annotations[0]->text);
    }

    private function writeSiteConfiguration(): void
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
            'pageMetrics' => ['sources' => ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']]],
        ]);
    }

    private function writeSiteConfigurationWithoutSource(): void
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
