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

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricSignificance;
use PHPUnit\Framework\TestCase;

/**
 * MetricSignificanceTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MetricSignificanceTest extends TestCase
{
    public function testBothThresholdsHaveToBeCleared(): void
    {
        $subject = new MetricSignificance(0.1, 10);

        // 2 to 4 doubles, and is four views.
        self::assertFalse($subject->clears(4, 2));
        // 4000 to 3960 is forty views, and is one percent.
        self::assertFalse($subject->clears(3960, 4000));
        // 100 to 130 is thirty views and three tenths.
        self::assertTrue($subject->clears(130, 100));
    }

    public function testAFallCountsTheSameAsARise(): void
    {
        $subject = new MetricSignificance(0.1, 10);

        self::assertTrue($subject->clears(70, 100));
    }

    public function testExactlyOnAThresholdCounts(): void
    {
        $subject = new MetricSignificance(0.1, 10);

        self::assertTrue($subject->clears(110, 100));
    }

    public function testNothingClearsAThresholdAgainstAnEmptyPreviousPeriod(): void
    {
        // There is no share of nothing, and such a page is reported as "new"
        // rather than as a mover.
        $subject = new MetricSignificance(0.1, 10);

        self::assertFalse($subject->clears(5000, 0));
    }

    public function testTheDefaultHasNoAbsoluteFloor(): void
    {
        $subject = MetricSignificance::default();

        self::assertSame(0, $subject->absolute);
        self::assertTrue($subject->clears(4, 2));
        self::assertFalse($subject->clears(104, 100));
    }
}
