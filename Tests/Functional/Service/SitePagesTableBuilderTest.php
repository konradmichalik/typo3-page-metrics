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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricUnit, TrendDirection};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, SitePagesColumn, SitePagesExportRow, SitePagesRow, SitePagesTable};
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, ImportStateRepository};
use KonradMichalik\Typo3PageMetrics\Service\SitePagesTableBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
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

    public function testRanksDecayingPagesFirstAndTheLongestUntouchedAmongThem(): void
    {
        $this->writeSiteConfiguration();
        // All three lost the same share of the same metric. What separates
        // them is only how long nobody has touched them, which is the whole
        // claim the decay order makes.
        $this->fell(1, 200, 40);
        $this->touchPage(1, '-900 days');
        $this->fell(2, 200, 40);
        $this->touchPage(2, '-100 days');
        $this->fell(3, 200, 40);
        $this->touchPage(3, '-2 days');

        $table = $this->build($this->site(), SitePagesTable::SORT_DECAY);

        self::assertSame([1, 2, 3], $this->uidsOf($table));
        self::assertTrue($this->rowFor($table, 1)->isDecaying);
        self::assertTrue($this->rowFor($table, 2)->isDecaying);
        // Edited two days ago, so the fall happened to content somebody is
        // working on. That is a change with an effect, not neglect.
        self::assertFalse($this->rowFor($table, 3)->isDecaying);
    }

    public function testReversesTheDecayOrderWhenAscendingIsAskedFor(): void
    {
        $this->writeSiteConfiguration();
        $this->fell(1, 200, 40);
        $this->touchPage(1, '-900 days');
        $this->fell(2, 200, 40);
        $this->touchPage(2, '-2 days');

        $table = $this->build($this->site(), SitePagesTable::SORT_DECAY, SitePagesTable::DIRECTION_ASCENDING);

        self::assertSame([2, 1], $this->uidsOf($table));
    }

    public function testDoesNotCallAPageDecayingWhenItsFallIsTooSmallToBeSignificant(): void
    {
        $this->writeSiteConfiguration();
        // Five views off two hundred, on a metric whose provider set a floor
        // of ten. The same rule the trend widget and the compare view apply,
        // so a page does not become a task because of noise.
        $this->fell(1, 200, 195);
        $this->touchPage(1, '-900 days');

        self::assertFalse($this->build($this->site(), SitePagesTable::SORT_DECAY)->rows[0]->isDecaying);
    }

    public function testDoesNotCallAPageDecayingWhenItRose(): void
    {
        $this->writeSiteConfiguration();
        $this->fell(1, 40, 200);
        $this->touchPage(1, '-900 days');

        self::assertFalse($this->build($this->site(), SitePagesTable::SORT_DECAY)->rows[0]->isDecaying);
    }

    public function testAPageThatWasNeverFoundIsNotDecaying(): void
    {
        $this->writeSiteConfiguration();
        // Listed because another metric was recorded for it, but the metric
        // the verdict is made on has never been anything but zero. Nothing
        // fell here; it was never found, which is a different job.
        $this->upsert(2, 'matomo.visits', $this->today, 3);
        $this->touchPage(2, '-900 days');
        $this->fell(1, 200, 40);
        $this->touchPage(1, '-100 days');

        $table = $this->build($this->site(), SitePagesTable::SORT_DECAY);

        self::assertFalse($this->rowFor($table, 2)->isDecaying);
        // And it sorts below the page that really is decaying, even though
        // it is the older of the two.
        self::assertSame([1, 2], $this->uidsOf($table));
    }

    public function testMarksNoPageAsDecayingWhenThePreviousPeriodWasNeverImported(): void
    {
        $this->writeSiteConfiguration();
        $this->fell(1, 200, 40);
        $this->touchPage(1, '-900 days');
        $this->get(ImportStateRepository::class)->markImported(
            'matomo',
            'main',
            $this->today,
            new DateRange($this->today->modify('-6 days'), $this->today),
            true,
        );

        $table = $this->build($this->site(), SitePagesTable::SORT_DECAY);

        self::assertFalse($table->previousPeriodCovered);
        self::assertFalse($table->rows[0]->isDecaying);
    }

    public function testStillLeavesOutPagesTheBackendUserMayNotSeeWhenSortingByDecay(): void
    {
        $this->writeSiteConfiguration();
        $this->fell(2, 200, 40);
        $this->touchPage(2, '-100 days');
        $this->fell(3, 200, 40);
        $this->touchPage(3, '-900 days');

        $this->setUpBackendUser(2);
        $table = $this->build($this->site(), SitePagesTable::SORT_DECAY);

        // Page 3 is the older and the more decayed of the two, which is
        // exactly why leaving it in for a reader who cannot open it would
        // put it at the top of their screen.
        self::assertSame([2], $this->uidsOf($table));
    }

    public function testReportsHowLongAgoEachPageWasChanged(): void
    {
        $this->writeSiteConfiguration();
        $this->upsert(1, 'matomo.pageviews', $this->today, 5);
        $this->touchPage(1, '-42 days');

        self::assertSame(42, $this->build($this->site())->rows[0]->daysSinceChange);
    }

    public function testHasNoAgeForAPageWhoseRecordCarriesNoTimestamp(): void
    {
        $this->writeSiteConfiguration();
        // The fixture leaves tstamp at zero, which is not "changed in 1970"
        // but "nobody recorded when", and a page of unknown age must not
        // head a list ordered by age.
        $this->upsert(1, 'matomo.pageviews', $this->today, 5);

        $row = $this->build($this->site())->rows[0];

        self::assertNull($row->daysSinceChange);
        self::assertFalse($row->isDecaying);
    }

    public function testKeepsTheTrendOnTheSitesPrimaryMetricWhileSortingByDecay(): void
    {
        $this->writeSiteConfiguration();
        $this->fell(1, 200, 40);
        $this->touchPage(1, '-900 days');

        $table = $this->build($this->site(), SitePagesTable::SORT_DECAY);

        self::assertSame(SitePagesTable::SORT_DECAY, $table->sortKey);
        self::assertSame('Page views', $table->trendLabel);
        self::assertSame(-80, $table->rows[0]->deltaPercent);
        // No metric column claims the order, because none of them holds it.
        self::assertSame([false, false, false, false], array_map(static fn ($column): bool => $column->isSorted, $table->columns));
    }

    public function testStreamsEveryRowAndNotOnlyThePageTheTableShows(): void
    {
        $this->writeSiteConfiguration();
        $this->importCSVDataSet(__DIR__.'/Fixtures/many-pages.csv');

        foreach (range(10, 40) as $uid) {
            $this->upsert($uid, 'matomo.pageviews', $this->today, $uid);
        }

        self::assertCount(31, $this->stream($this->site()));
    }

    public function testStreamsRawValuesInTheirOwnUnitRatherThanTheStringTheTableDraws(): void
    {
        $this->writeSiteConfiguration();
        $this->upsert(1, 'matomo.pageviews', $this->today, 4);
        $this->upsert(1, 'matomo.visits', $this->today, 2);
        $this->upsert(1, 'matomo.time_spent_seconds', $this->today, 366);

        $exported = $this->stream($this->site())[0];

        // Three minutes and three seconds, which the table draws as "3:03"
        // and the file has to carry as the number the recipient divides.
        self::assertSame('3:03', $this->build($this->site())->rows[0]->cells[2]->formattedValue);
        self::assertSame(183, $exported->values['matomo.avg_time_on_page']);
        self::assertSame(4, $exported->values['matomo.pageviews']);
        // A rate with nothing to divide is no value rather than a zero, the
        // same reading the table's own cell makes of it.
        self::assertNull($exported->values['matomo.bounce_rate']);
    }

    public function testStreamsTheRowsInTheSameOrderTheTableShowsThem(): void
    {
        $this->writeSiteConfiguration();
        $this->fell(1, 200, 40);
        $this->touchPage(1, '-100 days');
        $this->fell(2, 200, 40);
        $this->touchPage(2, '-900 days');
        $this->fell(3, 800, 780);
        $this->touchPage(3, '-400 days');

        $exported = $this->stream($this->site(), SitePagesTable::SORT_DECAY);

        self::assertSame(
            $this->uidsOf($this->build($this->site(), SitePagesTable::SORT_DECAY)),
            array_map(static fn (SitePagesExportRow $row): int => $row->pageUid, $exported),
        );
    }

    public function testStreamCarriesWhatPutADecaySortedRowWhereItIs(): void
    {
        $this->writeSiteConfiguration();
        $this->fell(1, 200, 40);
        $this->touchPage(1, '-900 days');

        $exported = $this->stream($this->site(), SitePagesTable::SORT_DECAY)[0];

        self::assertTrue($exported->isDecaying);
        self::assertSame(900, $exported->daysSinceChange);
        self::assertSame(-80, $exported->deltaPercent);
        self::assertTrue($exported->hasComparison);
    }

    public function testStreamsNothingForASiteThatConfiguredNoProvider(): void
    {
        $this->writeSiteConfigurationWithoutProvider();

        self::assertSame([], $this->stream($this->site()));
    }

    public function testStreamLeavesOutPagesTheBackendUserMayNotSee(): void
    {
        $this->writeSiteConfiguration();
        $this->upsert(2, 'matomo.pageviews', $this->today, 5);
        $this->upsert(3, 'matomo.pageviews', $this->today, 500);

        $this->setUpBackendUser(2);

        self::assertSame([2], array_map(static fn (SitePagesExportRow $row): int => $row->pageUid, $this->stream($this->site())));
    }

    public function testNamesTheUnitOfEveryExportedColumnSoTheFileNeedNotGuess(): void
    {
        $this->writeSiteConfiguration();

        $columns = $this->get(SitePagesTableBuilder::class)->columnsOf($this->site());

        self::assertSame(
            [MetricUnit::Count, MetricUnit::Count, MetricUnit::Seconds, MetricUnit::Percent],
            array_map(static fn (SitePagesColumn $column): ?MetricUnit => $column->unit, $columns),
        );
    }

    /**
     * @return list<SitePagesExportRow>
     */
    private function stream(Site $site, string $sortKey = '', string $direction = ''): array
    {
        return iterator_to_array($this->get(SitePagesTableBuilder::class)->stream($site, 0, 7, $sortKey, $direction), false);
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

    /**
     * The site's primary metric at $previousValue in the period before this
     * one and at $value in it, which is the only shape a decay verdict can
     * be made from.
     */
    private function fell(int $pageUid, int $previousValue, int $value): void
    {
        $this->upsert($pageUid, 'matomo.pageviews', $this->today->modify('-10 days'), $previousValue);
        $this->upsert($pageUid, 'matomo.pageviews', $this->today, $value);
    }

    /**
     * Written into the row rather than into the fixture, because an age only
     * means something relative to the moment the test runs and the fixture
     * is shared with the tests above.
     */
    private function touchPage(int $pageUid, string $modifier): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update(
            'pages',
            ['tstamp' => $this->today->modify($modifier)->getTimestamp()],
            ['uid' => $pageUid],
        );
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
