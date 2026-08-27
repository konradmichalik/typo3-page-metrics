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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{TrendDirection, UnresolvedReason};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{MetricTile, PageMetricsViewData};
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, UnresolvedUrlRepository};
use KonradMichalik\Typo3PageMetrics\Service\PageMetricsViewDataBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function sprintf;

/**
 * PageMetricsViewDataBuilderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageMetricsViewDataBuilderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    public function testReturnsNoPageWhenPageIdIsZero(): void
    {
        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(0, 0, 30);

        self::assertFalse($viewData->hasPage);
        self::assertSame([], $viewData->tiles);
    }

    public function testReturnsNoPageWhenSiteCannotBeResolved(): void
    {
        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);

        self::assertFalse($viewData->hasPage);
    }

    public function testReturnsNoPageForASysfolder(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(4, 0, 30);

        self::assertFalse($viewData->hasPage);
        self::assertSame([], $viewData->tiles);
    }

    public function testSparklineCoversTheSelectedPeriodInsteadOfAFixedWindow(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 1);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7), 'matomo.pageviews');
        self::assertCount(7, $tile->sparkline);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 90), 'matomo.pageviews');
        self::assertCount(90, $tile->sparkline);
    }

    public function testExposesTheCurrentAndPreviousPeriodAsDateRangeLabels(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        self::assertSame(
            sprintf('%s – %s', $today->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')),
            $viewData->currentRangeLabel,
        );
        self::assertSame(
            sprintf('%s – %s', $today->modify('-13 days')->format('Y-m-d'), $today->modify('-7 days')->format('Y-m-d')),
            $viewData->previousRangeLabel,
        );
    }

    public function testReturnsTileTotalForConfiguredMetric(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);

        self::assertTrue($viewData->hasPage);
        self::assertCount(4, $viewData->tiles);
        $pageviewsTile = array_values(array_filter($viewData->tiles, static fn ($tile) => 'matomo.pageviews' === $tile->identifier))[0];
        self::assertSame(42, $pageviewsTile->value);
    }

    public function testComputesUpwardTrendAgainstThePreviousPeriod(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 20);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.pageviews', 10);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        $tile = $this->tileFor($viewData, 'matomo.pageviews');
        self::assertSame(20, $tile->value);
        self::assertSame(100, $tile->deltaPercent);
        self::assertSame(TrendDirection::Up, $tile->trend);
        self::assertSame('10', $tile->previousFormattedValue);
    }

    public function testReportsNewInsteadOfAPercentWhenThePreviousPeriodHadNoData(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 5);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        $tile = $this->tileFor($viewData, 'matomo.pageviews');
        self::assertNull($tile->deltaPercent);
        self::assertSame(TrendDirection::Up, $tile->trend);
        self::assertSame('', $tile->previousFormattedValue);
    }

    public function testReportsFlatTrendWhenBothPeriodsHaveNoData(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        $tile = $this->tileFor($viewData, 'matomo.pageviews');
        self::assertNull($tile->deltaPercent);
        self::assertSame(TrendDirection::Flat, $tile->trend);
    }

    public function testComputesRedirectSharePercentFromTheViaRedirectDimension(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 30, '');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 6, 'via_redirect');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        $tile = $this->tileFor($viewData, 'matomo.pageviews');
        self::assertSame(20, $tile->redirectSharePercent);
    }

    public function testComputesBounceRateAsARatioOfSumsNotAnAverageOfDailyRatios(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        // Day 1: 5/10 = 50% bounce rate on low traffic.
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entry_bounces', 5);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entries', 10);
        // Day 2: 9/90 = 10% bounce rate on high traffic.
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-1 day'), 'matomo.entry_bounces', 9);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-1 day'), 'matomo.entries', 90);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        $tile = $this->tileFor($viewData, 'matomo.bounce_rate');
        // Naive averaging of the two daily ratios would give (50+10)/2 = 30%;
        // the correct sum(bounces)/sum(entries) = 14/100 = 14%.
        self::assertSame(14, $tile->value);
        self::assertSame('14%', $tile->formattedValue);
    }

    public function testComputesAvgTimeOnPageFromSummedTimeSpentAndVisits(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.time_spent_seconds', 300);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.visits', 3);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        $tile = $this->tileFor($viewData, 'matomo.avg_time_on_page');
        self::assertSame(100, $tile->value);
        self::assertSame('1:40', $tile->formattedValue);
    }

    public function testRateTilesHaveNoRedirectShare(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        $tile = $this->tileFor($viewData, 'matomo.bounce_rate');
        self::assertSame(0, $tile->redirectSharePercent);
    }

    public function testBuildsAnExternalLinkFromTheResolvedPageUrl(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        $tile = $this->tileFor($viewData, 'matomo.pageviews');
        self::assertNotNull($tile->externalLink);
        self::assertStringContainsString('matomo.example.com', $tile->externalLink);
        self::assertStringContainsString(rawurlencode('pageUrl==https://example.com/about'), $tile->externalLink);
    }

    public function testDefaultsToInvalidPeriodFallback(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 999);

        self::assertSame(30, $viewData->periodDays);
    }

    public function testCountsUnresolvedUrlsForTheSiteWithinThirtyDays(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $this->get(UnresolvedUrlRepository::class)->record(
            'matomo',
            new DateTimeImmutable('today'),
            'https://example.com/gone',
            'matomo.pageviews',
            UnresolvedReason::Unknown,
            3,
            100,
        );

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);

        self::assertSame(1, $viewData->unresolvedCount);
    }

    public function testAnnotationsAreEmptyWhenNoneAreStored(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);

        self::assertSame([], $viewData->annotations);
    }

    public function testAnnotationsWithinTheSelectedPeriodAreExposed(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', '1', new DateTimeImmutable('today'), 'Release 2.0');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);

        self::assertCount(1, $viewData->annotations);
        self::assertSame('Release 2.0', $viewData->annotations[0]->text);
    }

    public function testIncludeSubpagesSumsMetricsAcrossTheWholeSubtree(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 32);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(1, 0, 30, true);

        $tile = $this->tileFor($viewData, 'matomo.pageviews');
        self::assertSame(42, $tile->value);
    }

    public function testIncludeSubpagesFalseKeepsSinglePageTotals(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 32);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(1, 0, 30, false);

        $tile = $this->tileFor($viewData, 'matomo.pageviews');
        self::assertSame(10, $tile->value);
    }

    public function testIncludeSubpagesHidesTheExternalLink(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(1, 0, 30, true);

        $tile = $this->tileFor($viewData, 'matomo.pageviews');
        self::assertNull($tile->externalLink);
    }

    public function testIncludeSubpagesFlagIsExposedOnTheViewData(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        self::assertTrue($this->get(PageMetricsViewDataBuilder::class)->build(1, 0, 30, true)->includeSubpages);
        self::assertFalse($this->get(PageMetricsViewDataBuilder::class)->build(1, 0, 30, false)->includeSubpages);
    }

    public function testHasSubpagesIsTrueForAPageWithChildren(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        self::assertTrue($this->get(PageMetricsViewDataBuilder::class)->build(1, 0, 30)->hasSubpages);
    }

    public function testHasSubpagesIsFalseForALeafPage(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        self::assertFalse($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30)->hasSubpages);
    }

    private function tileFor(PageMetricsViewData $viewData, string $identifier): MetricTile
    {
        foreach ($viewData->tiles as $tile) {
            if ($tile->identifier === $identifier) {
                return $tile;
            }
        }

        self::fail(sprintf('No tile found for metric identifier "%s".', $identifier));
    }

    private function writeSiteConfiguration(int $rootPageId, string $base): void
    {
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => $rootPageId,
            'base' => $base,
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
}
