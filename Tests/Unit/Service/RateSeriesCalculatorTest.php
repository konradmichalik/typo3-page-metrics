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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use KonradMichalik\Typo3PageMetrics\Service\RateSeriesCalculator;
use PHPUnit\Framework\TestCase;

/**
 * RateSeriesCalculatorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RateSeriesCalculatorTest extends TestCase
{
    private RateSeriesCalculator $subject;

    protected function setUp(): void
    {
        $this->subject = new RateSeriesCalculator();
    }

    public function testPercentRatioIsRoundedToWholePercent(): void
    {
        self::assertSame(33, $this->subject->ratio(1, 3, MetricUnit::Percent));
    }

    public function testSecondsRatioIsRoundedToWholeSeconds(): void
    {
        self::assertSame(25, $this->subject->ratio(100, 4, MetricUnit::Seconds));
    }

    public function testAZeroDenominatorIsZeroRatherThanADivisionByZero(): void
    {
        self::assertSame(0, $this->subject->ratio(5, 0, MetricUnit::Percent));
    }

    public function testSeriesDividesEachDayIndependently(): void
    {
        $series = $this->subject->series(
            ['2026-08-01' => 1, '2026-08-02' => 3],
            ['2026-08-01' => 4, '2026-08-02' => 4],
            MetricUnit::Percent,
        );

        self::assertSame(['2026-08-01' => 25, '2026-08-02' => 75], $series);
    }

    public function testADayWithoutADenominatorIsZeroNotADivisionByZero(): void
    {
        $series = $this->subject->series(
            ['2026-08-01' => 5],
            [],
            MetricUnit::Percent,
        );

        self::assertSame(['2026-08-01' => 0], $series);
    }

    public function testTheSeriesFollowsTheNumeratorsDays(): void
    {
        // The denominator can carry days the numerator does not; the result
        // covers exactly the days asked for, which is the range both were
        // queried over.
        $series = $this->subject->series(
            ['2026-08-01' => 1],
            ['2026-08-01' => 2, '2026-08-02' => 8],
            MetricUnit::Percent,
        );

        self::assertSame(['2026-08-01' => 50], $series);
    }
}
