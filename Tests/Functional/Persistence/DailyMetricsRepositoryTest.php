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
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Persistence\DailyMetricsRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * DailyMetricsRepositoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class DailyMetricsRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private DailyMetricsRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(DailyMetricsRepository::class);
    }

    public function testUpsertInsertsNewRow(): void
    {
        $this->subject->upsert(2, 0, 'matomo', new DateTimeImmutable('2026-08-01'), 'matomo.pageviews', 42);

        $series = $this->subject->dailySeries(2, 0, 'matomo.pageviews', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-01'),
        ));

        self::assertSame(['2026-08-01' => 42], $series);
    }

    public function testUpsertUpdatesExistingRowInsteadOfDuplicating(): void
    {
        $date = new DateTimeImmutable('2026-08-01');
        $this->subject->upsert(2, 0, 'matomo', $date, 'matomo.pageviews', 42);
        $this->subject->upsert(2, 0, 'matomo', $date, 'matomo.pageviews', 99);

        $series = $this->subject->dailySeries(2, 0, 'matomo.pageviews', new DateRange($date, $date));

        self::assertSame(['2026-08-01' => 99], $series);
    }

    public function testDailySeriesReturnsZeroForDaysWithoutARow(): void
    {
        $this->subject->upsert(2, 0, 'matomo', new DateTimeImmutable('2026-08-01'), 'matomo.pageviews', 5);

        $series = $this->subject->dailySeries(2, 0, 'matomo.pageviews', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-03'),
        ));

        self::assertSame(['2026-08-01' => 5, '2026-08-02' => 0, '2026-08-03' => 0], $series);
    }

    public function testDistinguishesRowsByLanguageAndMetric(): void
    {
        $date = new DateTimeImmutable('2026-08-01');
        $this->subject->upsert(2, 0, 'matomo', $date, 'matomo.pageviews', 10);
        $this->subject->upsert(2, 1, 'matomo', $date, 'matomo.pageviews', 20);
        $this->subject->upsert(2, 0, 'matomo', $date, 'matomo.visits', 7);

        self::assertSame(['2026-08-01' => 10], $this->subject->dailySeries(2, 0, 'matomo.pageviews', new DateRange($date, $date)));
        self::assertSame(['2026-08-01' => 20], $this->subject->dailySeries(2, 1, 'matomo.pageviews', new DateRange($date, $date)));
        self::assertSame(['2026-08-01' => 7], $this->subject->dailySeries(2, 0, 'matomo.visits', new DateRange($date, $date)));
    }

    public function testDailySeriesFiltersByDimension(): void
    {
        $date = new DateTimeImmutable('2026-08-01');
        $this->subject->upsert(2, 0, 'matomo', $date, 'matomo.pageviews', 30, '');
        $this->subject->upsert(2, 0, 'matomo', $date, 'matomo.pageviews', 12, 'via_redirect');

        self::assertSame(['2026-08-01' => 30], $this->subject->dailySeries(2, 0, 'matomo.pageviews', new DateRange($date, $date)));
        self::assertSame(['2026-08-01' => 12], $this->subject->dailySeries(2, 0, 'matomo.pageviews', new DateRange($date, $date), 'via_redirect'));
    }

    public function testSiteSeriesSumsAcrossPages(): void
    {
        $date = new DateTimeImmutable('2026-08-01');
        $this->subject->upsert(2, 0, 'matomo', $date, 'matomo.pageviews', 10);
        $this->subject->upsert(3, 0, 'matomo', $date, 'matomo.pageviews', 25);

        $series = $this->subject->siteSeries([2, 3], 0, 'matomo.pageviews', new DateRange($date, $date));

        self::assertSame(['2026-08-01' => 35], $series);
    }

    public function testSiteSeriesZeroFillsDaysWithoutARow(): void
    {
        // A provider only emits a metric for a URL that had activity, so a
        // day with no row is an ordinary quiet day. Whether the day was
        // imported at all is ImportCoverage's question, not this one.
        $this->subject->upsert(2, 0, 'matomo', new DateTimeImmutable('2026-08-01'), 'matomo.pageviews', 5);

        $series = $this->subject->siteSeries([2, 3], 0, 'matomo.pageviews', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-02'),
        ));

        self::assertSame(['2026-08-01' => 5, '2026-08-02' => 0], $series);
    }

    public function testSiteSeriesFiltersByDimension(): void
    {
        $date = new DateTimeImmutable('2026-08-01');
        $this->subject->upsert(2, 0, 'matomo', $date, 'matomo.pageviews', 30, '');
        $this->subject->upsert(2, 0, 'matomo', $date, 'matomo.pageviews', 12, 'via_redirect');
        $this->subject->upsert(3, 0, 'matomo', $date, 'matomo.pageviews', 8, 'via_redirect');

        self::assertSame(['2026-08-01' => 30], $this->subject->siteSeries([2, 3], 0, 'matomo.pageviews', new DateRange($date, $date)));
        self::assertSame(['2026-08-01' => 20], $this->subject->siteSeries([2, 3], 0, 'matomo.pageviews', new DateRange($date, $date), 'via_redirect'));
    }

    public function testSumsByPageShortCircuitsOnAnEmptyPageList(): void
    {
        $this->subject->upsert(2, 0, 'matomo', new DateTimeImmutable('2026-08-01'), 'matomo.pageviews', 42);

        $sums = $this->subject->sumsByPage([], 0, 'matomo.pageviews', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-01'),
        ));

        self::assertSame([], $sums);
    }

    public function testSumsByPageOnlyReturnsPagesThatHaveARow(): void
    {
        $this->subject->upsert(2, 0, 'matomo', new DateTimeImmutable('2026-08-01'), 'matomo.pageviews', 42);
        $this->subject->upsert(2, 0, 'matomo', new DateTimeImmutable('2026-08-02'), 'matomo.pageviews', 8);

        $sums = $this->subject->sumsByPage([2, 3], 0, 'matomo.pageviews', new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-02'),
        ));

        self::assertSame([2 => 50], $sums);
    }

    public function testSumsByDimensionPrefixAddsUpOnePrefixAcrossDaysAndPages(): void
    {
        $first = new DateTimeImmutable('2026-08-01');
        $second = new DateTimeImmutable('2026-08-02');
        $this->subject->upsert(2, 0, 'matomo', $first, 'matomo.visits', 6, 'channel:search');
        $this->subject->upsert(2, 0, 'matomo', $second, 'matomo.visits', 4, 'channel:search');
        $this->subject->upsert(3, 0, 'matomo', $first, 'matomo.visits', 5, 'channel:direct');

        $sums = $this->subject->sumsByDimensionPrefix([2, 3], 0, 'matomo.visits', new DateRange($first, $second), 'channel:');

        // Ordered by dimension so the caller gets the same array twice
        // for the same data, whatever the platform's grouping does.
        self::assertSame(['channel:direct' => 5, 'channel:search' => 10], $sums);
    }

    public function testSumsByDimensionPrefixIgnoresTheHeadlineAndOtherDimensions(): void
    {
        $date = new DateTimeImmutable('2026-08-01');
        $this->subject->upsert(2, 0, 'matomo', $date, 'matomo.visits', 30);
        $this->subject->upsert(2, 0, 'matomo', $date, 'matomo.visits', 20, 'via_redirect');
        $this->subject->upsert(2, 0, 'matomo', $date, 'matomo.visits', 7, 'channel:search');

        self::assertSame(
            ['channel:search' => 7],
            $this->subject->sumsByDimensionPrefix([2], 0, 'matomo.visits', new DateRange($date, $date), 'channel:'),
        );
    }

    public function testSumsByDimensionPrefixShortCircuitsOnAnEmptyPageList(): void
    {
        $date = new DateTimeImmutable('2026-08-01');
        $this->subject->upsert(2, 0, 'matomo', $date, 'matomo.visits', 7, 'channel:search');

        self::assertSame([], $this->subject->sumsByDimensionPrefix([], 0, 'matomo.visits', new DateRange($date, $date), 'channel:'));
    }

    public function testSumsByDimensionPrefixTreatsAWildcardInThePrefixAsLiteralText(): void
    {
        // A prefix is a prefix, not a LIKE pattern: an underscore in one
        // must not quietly match any character.
        $date = new DateTimeImmutable('2026-08-01');
        $this->subject->upsert(2, 0, 'matomo', $date, 'matomo.visits', 7, 'channelXsearch');

        self::assertSame([], $this->subject->sumsByDimensionPrefix([2], 0, 'matomo.visits', new DateRange($date, $date), 'channel_'));
    }
}
