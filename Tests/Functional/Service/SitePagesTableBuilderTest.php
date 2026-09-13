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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\TrendDirection;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, SitePagesRow, SitePagesTable};
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, ImportStateRepository};
use KonradMichalik\Typo3PageMetrics\Service\SitePagesTableBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SitePagesTableBuilderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SitePagesTableBuilderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
        $this->today = new DateTimeImmutable('today');
    }

    public function testReportsNoProviderForASiteThatConfiguredNone(): void
    {
        $this->writeSiteConfigurationWithoutProvider();

        $table = $this->build($this->site());

        self::assertFalse($table->hasProvider);
        self::assertSame([], $table->rows);
        self::assertSame([], $table->columns);
    }

    public function testListsOneColumnPerMetricInTheProvidersOwnOrder(): void
    {
        $this->writeSiteConfiguration();
        $this->upsert(1, 'matomo.pageviews', $this->today, 5);

        $table = $this->build($this->site());

        self::assertSame(
            ['matomo.pageviews', 'matomo.visits', 'matomo.avg_time_on_page', 'matomo.bounce_rate'],
            array_map(static fn ($column): string => $column->key, $table->columns),
        );
    }

    public function testOmitsPagesTheBackendUserMayNotSee(): void
    {
        $this->writeSiteConfiguration();
        $this->upsert(2, 'matomo.pageviews', $this->today, 5);
        $this->upsert(3, 'matomo.pageviews', $this->today, 500);

        $asAdmin = $this->build($this->site());
        $this->setUpBackendUser(2);
        $asEditor = $this->build($this->site());

        self::assertSame([3, 2], $this->uidsOf($asAdmin));
        self::assertSame([2], $this->uidsOf($asEditor));
    }

    public function testSortsByTheSitesFirstMetricDescendingWhenNothingWasAskedFor(): void
    {
        $this->writeSiteConfiguration();
        $this->upsert(1, 'matomo.pageviews', $this->today, 3);
        $this->upsert(2, 'matomo.pageviews', $this->today, 9);

        $table = $this->build($this->site());

        self::assertSame('matomo.pageviews', $table->sortKey);
        self::assertSame(SitePagesTable::DIRECTION_DESCENDING, $table->sortDirection);
        self::assertSame([2, 1], $this->uidsOf($table));
    }

    public function testSortsByAnyRequestedMetricInEitherDirection(): void
    {
        $this->writeSiteConfiguration();
        // Page 1 is the busier page but bounces more, which is the whole
        // point of the view: 400th by views can be first by bounce rate.
        $this->upsert(1, 'matomo.pageviews', $this->today, 100);
        $this->upsert(1, 'matomo.entries', $this->today, 10);
        $this->upsert(1, 'matomo.entry_bounces', $this->today, 9);
        $this->upsert(2, 'matomo.pageviews', $this->today, 10);
        $this->upsert(2, 'matomo.entries', $this->today, 10);
        $this->upsert(2, 'matomo.entry_bounces', $this->today, 1);

        $worstFirst = $this->build($this->site(), 'matomo.bounce_rate', SitePagesTable::DIRECTION_DESCENDING);
        $bestFirst = $this->build($this->site(), 'matomo.bounce_rate', SitePagesTable::DIRECTION_ASCENDING);

        self::assertSame([1, 2], $this->uidsOf($worstFirst));
        self::assertSame([2, 1], $this->uidsOf($bestFirst));
    }

    public function testFallsBackToTheFirstMetricWhenTheRequestedSortIsUnknown(): void
    {
        $this->writeSiteConfiguration();
        $this->upsert(1, 'matomo.pageviews', $this->today, 3);

        $table = $this->build($this->site(), 'ga4.sessions');

        self::assertSame('matomo.pageviews', $table->sortKey);
    }

    public function testACountWithNoRowReadsAsZeroAndARateWithNoDenominatorHasNoValue(): void
    {
        $this->writeSiteConfiguration();
        $this->upsert(1, 'matomo.pageviews', $this->today, 4);

        $table = $this->build($this->site());
        [$pageviews, $visits, $avgTime, $bounceRate] = $table->rows[0]->cells;

        self::assertTrue($pageviews->hasValue);
        self::assertSame('4', $pageviews->formattedValue);
        self::assertTrue($visits->hasValue);
        self::assertSame('0', $visits->formattedValue);
        self::assertFalse($avgTime->hasValue);
        self::assertFalse($bounceRate->hasValue);
    }

    public function testSortsPagesWithoutARecordedRateLastInBothDirections(): void
    {
        $this->writeSiteConfiguration();
        $this->upsert(1, 'matomo.pageviews', $this->today, 1);
        $this->upsert(2, 'matomo.pageviews', $this->today, 1);
        $this->upsert(2, 'matomo.entries', $this->today, 4);
        $this->upsert(2, 'matomo.entry_bounces', $this->today, 2);

        $descending = $this->build($this->site(), 'matomo.bounce_rate', SitePagesTable::DIRECTION_DESCENDING);
        $ascending = $this->build($this->site(), 'matomo.bounce_rate', SitePagesTable::DIRECTION_ASCENDING);

        self::assertSame([2, 1], $this->uidsOf($descending));
        self::assertSame([2, 1], $this->uidsOf($ascending));
    }

    public function testKeepsAPageThatRecordedNothingNowButSomethingBefore(): void
    {
        $this->writeSiteConfiguration();
        $this->upsert(1, 'matomo.pageviews', $this->today->modify('-10 days'), 80);
        $this->upsert(2, 'matomo.pageviews', $this->today, 5);

        $table = $this->build($this->site());
        $dying = $this->rowFor($table, 1);

        self::assertSame([2, 1], $this->uidsOf($table));
        self::assertSame(-100, $dying->deltaPercent);
        self::assertSame(TrendDirection::Down, $dying->trend);
    }

    public function testMarksAPageWithNoPreviousValueAsNew(): void
    {
        $this->writeSiteConfiguration();
        $this->upsert(1, 'matomo.pageviews', $this->today, 5);

        $row = $this->build($this->site())->rows[0];

        self::assertTrue($row->isNew);
        self::assertFalse($row->hasNoComparison);
        self::assertNull($row->deltaPercent);
    }

    public function testWithholdsTheTrendWhenThePreviousPeriodWasNeverImported(): void
    {
        $this->writeSiteConfiguration();
        $this->upsert(1, 'matomo.pageviews', $this->today, 5);
        // Covered for this period only, so the seven days it would be
        // compared against were never fetched.
        $this->get(ImportStateRepository::class)->markImported(
            'matomo',
            'main',
            $this->today,
            new DateRange($this->today->modify('-6 days'), $this->today),
            true,
        );

        $table = $this->build($this->site());

        self::assertFalse($table->previousPeriodCovered);
        self::assertTrue($table->rows[0]->hasNoComparison);
        self::assertFalse($table->rows[0]->isNew);
    }

    public function testPagesTheRowsAndReportsTheFullTotal(): void
    {
        $this->writeSiteConfiguration();
        $this->importCSVDataSet(__DIR__.'/Fixtures/many-pages.csv');

        foreach (range(10, 40) as $uid) {
            $this->upsert($uid, 'matomo.pageviews', $this->today, $uid);
        }

        $first = $this->build($this->site());
        $second = $this->build($this->site(), '', '', 2);
        $beyond = $this->build($this->site(), '', '', 99);

        self::assertSame(31, $first->totalRows);
        self::assertSame(2, $first->pageCount);
        self::assertCount(SitePagesTable::PAGE_SIZE, $first->rows);
        self::assertCount(31 - SitePagesTable::PAGE_SIZE, $second->rows);
        self::assertSame(40, $first->rows[0]->pageUid);
        self::assertSame(15, $second->rows[0]->pageUid);
        // Out of range clamps rather than showing an empty table: a stale
        // bookmark must not read as "this site has no pages".
        self::assertSame(2, $beyond->pageNumber);
    }

    public function testResolvesEachRowsCurrentPageTitle(): void
    {
        $this->writeSiteConfiguration();
        $this->upsert(2, 'matomo.pageviews', $this->today, 5);

        self::assertSame('About', $this->build($this->site())->rows[0]->title);
    }

    private function build(Site $site, string $sortKey = '', string $direction = '', int $pageNumber = 1): SitePagesTable
    {
        return $this->get(SitePagesTableBuilder::class)->build($site, 0, 7, $sortKey, $direction, $pageNumber);
    }

    private function site(): Site
    {
        return $this->get(SiteFinder::class)->getSiteByPageId(1);
    }

    /**
     * @return list<int>
     */
    private function uidsOf(SitePagesTable $table): array
    {
        return array_map(static fn (SitePagesRow $row): int => $row->pageUid, $table->rows);
    }

    private function rowFor(SitePagesTable $table, int $pageUid): SitePagesRow
    {
        foreach ($table->rows as $row) {
            if ($row->pageUid === $pageUid) {
                return $row;
            }
        }

        self::fail('No row for page '.$pageUid);
    }

    private function upsert(int $pageUid, string $metric, DateTimeImmutable $date, int $value): void
    {
        $this->get(DailyMetricsRepository::class)->upsert($pageUid, 0, 'matomo', $date, $metric, $value);
    }

    private function writeSiteConfiguration(): void
    {
        $this->writeSite(['providers' => ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']]]);
    }

    private function writeSiteConfigurationWithoutProvider(): void
    {
        $this->writeSite(null);
    }

    /**
     * @param array<string, mixed>|null $pageMetrics
     */
    private function writeSite(?array $pageMetrics): void
    {
        $configuration = [
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
        ];

        if (null !== $pageMetrics) {
            $configuration['pageMetrics'] = $pageMetrics;
        }

        $this->get(SiteWriter::class)->write('main', $configuration);
    }
}
