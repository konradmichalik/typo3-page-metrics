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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Domain\ValueObject;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DataFreshness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DataFreshnessTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class DataFreshnessTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function lastImportedDayProvider(): iterable
    {
        yield 'today is current' => ['2026-09-12', false];
        yield 'yesterday is current, the day may simply not have run yet' => ['2026-09-11', false];
        yield 'two days back is the last day still explainable by scheduling' => ['2026-09-10', false];
        yield 'three days back means no run has happened' => ['2026-09-09', true];
        yield 'a week back is plainly stale' => ['2026-09-05', true];
    }

    #[DataProvider('lastImportedDayProvider')]
    public function testMarksTheDataStaleOnlyOnceSchedulingNoLongerExplainsTheGap(string $date, bool $expectedStale): void
    {
        $freshness = new DataFreshness(new DateTimeImmutable($date), new DateTimeImmutable('2026-09-12'));

        self::assertSame($expectedStale, $freshness->isStale);
    }

    public function testKeepsTheDateItWasGiven(): void
    {
        $date = new DateTimeImmutable('2026-09-11');

        self::assertSame($date, (new DataFreshness($date, new DateTimeImmutable('2026-09-12')))->date);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function daysBehindProvider(): iterable
    {
        yield 'the same day is not behind at all' => ['2026-09-12', 0];
        yield 'yesterday is one day behind' => ['2026-09-11', 1];
        yield 'a week back counts every day of it' => ['2026-09-05', 7];
    }

    #[DataProvider('daysBehindProvider')]
    public function testCountsHowFarBehindTodayTheLastCoveredDayIs(string $date, int $expectedDaysBehind): void
    {
        $freshness = new DataFreshness(new DateTimeImmutable($date), new DateTimeImmutable('2026-09-12'));

        self::assertSame($expectedDaysBehind, $freshness->daysBehind);
    }

    public function testIgnoresTheTimeOfDayOnBothSidesOfTheComparison(): void
    {
        // The date comes from the import's own cursor and carries whatever
        // time the run wrote. An evening import read against a morning
        // request must still be the same day, not a day behind.
        $freshness = new DataFreshness(new DateTimeImmutable('2026-09-12 23:50:00'), new DateTimeImmutable('2026-09-12 00:10:00'));

        self::assertSame(0, $freshness->daysBehind);
    }

    public function testReportsNoGapWhereTheCursorIsAheadOfToday(): void
    {
        // A clock or timezone disagreement between the importing process and
        // the rendering one, not data from the future.
        $freshness = new DataFreshness(new DateTimeImmutable('2026-09-13'), new DateTimeImmutable('2026-09-12'));

        self::assertSame(0, $freshness->daysBehind);
    }
}
