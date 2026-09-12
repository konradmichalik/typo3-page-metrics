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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\TrendDirection;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricTrend;
use PHPUnit\Framework\TestCase;

/**
 * MetricTrendTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MetricTrendTest extends TestCase
{
    public function testReportsTheChangeAsAWholePercentage(): void
    {
        $trend = MetricTrend::between(150, 100);

        self::assertSame(50, $trend->deltaPercent);
        self::assertSame(TrendDirection::Up, $trend->direction);
    }

    public function testAFallIsNegativeAndPointsDown(): void
    {
        $trend = MetricTrend::between(80, 100);

        self::assertSame(-20, $trend->deltaPercent);
        self::assertSame(TrendDirection::Down, $trend->direction);
    }

    public function testAPeriodWithNoPredecessorIsNewRatherThanInfinite(): void
    {
        // Dividing by the previous period's zero has no answer, and reporting
        // one would be a number invented to fill the space.
        $trend = MetricTrend::between(42, 0);

        self::assertNull($trend->deltaPercent);
        self::assertSame(TrendDirection::Up, $trend->direction);
    }

    public function testTwoEmptyPeriodsAreFlatRatherThanNew(): void
    {
        $trend = MetricTrend::between(0, 0);

        self::assertNull($trend->deltaPercent);
        self::assertSame(TrendDirection::Flat, $trend->direction);
    }

    public function testAnUnchangedValueIsFlat(): void
    {
        $trend = MetricTrend::between(100, 100);

        self::assertSame(0, $trend->deltaPercent);
        self::assertSame(TrendDirection::Flat, $trend->direction);
    }
}
