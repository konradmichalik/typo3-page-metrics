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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Persistence;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, RecordReference};
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, RecordDailyMetricsRepository};
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * RecordDailyMetricsRepositoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RecordDailyMetricsRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private RecordDailyMetricsRepository $subject;

    private RecordReference $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(RecordDailyMetricsRepository::class);
        $this->article = new RecordReference('tx_news_domain_model_news', 42);
    }

    public function testUpsertInsertsNewRow(): void
    {
        $this->subject->upsert($this->article, 0, 'matomo', new DateTimeImmutable('2026-08-01'), 'matomo.pageviews', 17);

        self::assertSame(['2026-08-01' => 17], $this->subject->dailySeries($this->article, 0, 'matomo.pageviews', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-01'),
        )));
    }

    public function testUpsertUpdatesExistingRowInsteadOfDuplicating(): void
    {
        $date = new DateTimeImmutable('2026-08-01');
        $this->subject->upsert($this->article, 0, 'matomo', $date, 'matomo.pageviews', 17);
        $this->subject->upsert($this->article, 0, 'matomo', $date, 'matomo.pageviews', 99);

        self::assertSame(['2026-08-01' => 99], $this->subject->dailySeries($this->article, 0, 'matomo.pageviews', new DateRange($date, $date)));
        self::assertSame(1, $this->countRows());
    }

    public function testDailySeriesReturnsZeroForDaysWithoutARow(): void
    {
        $this->subject->upsert($this->article, 0, 'matomo', new DateTimeImmutable('2026-08-02'), 'matomo.pageviews', 5);

        self::assertSame(
            ['2026-08-01' => 0, '2026-08-02' => 5, '2026-08-03' => 0],
            $this->subject->dailySeries($this->article, 0, 'matomo.pageviews', new DateRange(
                new DateTimeImmutable('2026-08-01'),
                new DateTimeImmutable('2026-08-03'),
            )),
        );
    }

    public function testKeepsTheSameUidInDifferentTablesApart(): void
    {
        // The whole reason the table is keyed on (table, uid): uid 42 exists
        // in every record table there is.
        $date = new DateTimeImmutable('2026-08-01');
        $event = new RecordReference('tx_events_domain_model_event', 42);

        $this->subject->upsert($this->article, 0, 'matomo', $date, 'matomo.pageviews', 17);
        $this->subject->upsert($event, 0, 'matomo', $date, 'matomo.pageviews', 4);

        self::assertSame(['2026-08-01' => 17], $this->subject->dailySeries($this->article, 0, 'matomo.pageviews', new DateRange($date, $date)));
        self::assertSame(['2026-08-01' => 4], $this->subject->dailySeries($event, 0, 'matomo.pageviews', new DateRange($date, $date)));
    }

    public function testDistinguishesRowsByLanguageMetricAndDimension(): void
    {
        $date = new DateTimeImmutable('2026-08-01');
        $range = new DateRange($date, $date);

        $this->subject->upsert($this->article, 0, 'matomo', $date, 'matomo.pageviews', 17);
        $this->subject->upsert($this->article, 1, 'matomo', $date, 'matomo.pageviews', 3);
        $this->subject->upsert($this->article, 0, 'matomo', $date, 'matomo.visits', 11);
        $this->subject->upsert($this->article, 0, 'matomo', $date, 'matomo.pageviews', 2, 'via_redirect');

        self::assertSame(['2026-08-01' => 17], $this->subject->dailySeries($this->article, 0, 'matomo.pageviews', $range));
        self::assertSame(['2026-08-01' => 3], $this->subject->dailySeries($this->article, 1, 'matomo.pageviews', $range));
        self::assertSame(['2026-08-01' => 11], $this->subject->dailySeries($this->article, 0, 'matomo.visits', $range));
        self::assertSame(['2026-08-01' => 2], $this->subject->dailySeries($this->article, 0, 'matomo.pageviews', $range, 'via_redirect'));
        self::assertSame(4, $this->countRows());
    }

    public function testLeavesThePageFactTableAlone(): void
    {
        // The sibling table exists so the page module's hot read path never
        // pays for records. A record fact must not land in it, and a page
        // fact must not be visible from here.
        $date = new DateTimeImmutable('2026-08-01');
        $pages = $this->get(DailyMetricsRepository::class);
        $pages->upsert(3, 0, 'matomo', $date, 'matomo.pageviews', 100);

        $this->subject->upsert($this->article, 0, 'matomo', $date, 'matomo.pageviews', 17);

        self::assertSame(['2026-08-01' => 100], $pages->dailySeries(3, 0, 'matomo.pageviews', new DateRange($date, $date)));
        self::assertSame(1, $this->countRows());
    }

    private function countRows(): int
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_typo3pagemetrics_record_daily');

        return (int) $queryBuilder
            ->count('uid')
            ->from('tx_typo3pagemetrics_record_daily')
            ->executeQuery()
            ->fetchOne();
    }
}
