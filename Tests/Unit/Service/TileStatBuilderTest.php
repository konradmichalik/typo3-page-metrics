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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Service;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricUnit, TrendDirection};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{MetricTile, TileStats};
use KonradMichalik\Typo3PageMetrics\Service\{MetricValueFormatter, TileStatBuilder};
use PHPUnit\Framework\TestCase;

/**
 * TileStatBuilderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class TileStatBuilderTest extends TestCase
{
    public function testComputesLastDayPreviousDayPeakAndAverageFromTheSparkline(): void
    {
        $stats = $this->build([
            '2026-09-10' => 10,
            '2026-09-11' => 20,
            '2026-09-12' => 30,
        ]);

        self::assertSame('30', $stats->lastDayFormattedValue);
        self::assertSame('20', $stats->previousDayFormattedValue);
        self::assertSame('30', $stats->peakFormattedValue);
        self::assertSame('20', $stats->averageFormattedValue);
    }

    public function testADayWithNoDataIsExcludedFromThePeakAndTheAverageRatherThanCountedAsZero(): void
    {
        $stats = $this->build([
            '2026-09-10' => null,
            '2026-09-11' => 10,
            '2026-09-12' => 30,
        ]);

        self::assertSame('30', $stats->peakFormattedValue);
        // (10 + 30) / 2, not / 3 - a day with no number is not a day of zero
        // traffic, and must not pull the average down towards one.
        self::assertSame('20', $stats->averageFormattedValue);
    }

    public function testLastDayCanBeNullWhileAnEarlierDayCarriesData(): void
    {
        $stats = $this->build([
            '2026-09-10' => 10,
            '2026-09-11' => null,
        ]);

        self::assertNull($stats->lastDayFormattedValue);
        self::assertSame('10', $stats->previousDayFormattedValue);
    }

    public function testASinglePointHasNoPreviousDay(): void
    {
        $stats = $this->build(['2026-09-10' => 5]);

        self::assertSame('5', $stats->lastDayFormattedValue);
        self::assertNull($stats->previousDayFormattedValue);
    }

    public function testAnEmptySparklineYieldsNoStatsAtAll(): void
    {
        $stats = $this->build([]);

        self::assertNull($stats->lastDayFormattedValue);
        self::assertNull($stats->previousDayFormattedValue);
        self::assertNull($stats->peakFormattedValue);
        self::assertNull($stats->averageFormattedValue);
    }

    public function testFormatsEveryFigureInTheTilesOwnUnit(): void
    {
        $stats = $this->build(['2026-09-10' => 125], MetricUnit::Seconds);

        self::assertSame('2:05', $stats->lastDayFormattedValue);
        self::assertSame('2:05', $stats->peakFormattedValue);
    }

    /**
     * @param array<string, int|null> $sparkline
     */
    private function build(array $sparkline, MetricUnit $unit = MetricUnit::Count): TileStats
    {
        $tile = new MetricTile(
            'matomo.visits',
            'Visits',
            '',
            'tabler-users',
            '#000000',
            $unit,
            0,
            '0',
            $sparkline,
            null,
            TrendDirection::Flat,
            0,
            null,
            '',
        );

        return (new TileStatBuilder(new MetricValueFormatter()))->build($tile);
    }
}
