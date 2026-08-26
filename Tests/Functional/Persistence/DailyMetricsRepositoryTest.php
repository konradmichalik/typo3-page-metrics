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
}
