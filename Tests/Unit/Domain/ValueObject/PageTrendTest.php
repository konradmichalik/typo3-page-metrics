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
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\PageTrend;
use PHPUnit\Framework\TestCase;

/**
 * PageTrendTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageTrendTest extends TestCase
{
    public function testVolumeBreaksTheTieBetweenTwoEqualMovements(): void
    {
        // The same thousand views lost, once out of four thousand and once
        // out of a thousand and ten.
        $onABusyPage = self::trend(3000, 4000, 4000);
        $onAQuietOne = self::trend(10, 1010, 1010);

        self::assertGreaterThan($onAQuietOne->weight(), $onABusyPage->weight());
    }

    public function testVolumeCountsForLessThanTheMovementItself(): void
    {
        // A page ten times busier does not count for ten times as much: a
        // hundred views lost out of forty thousand stays behind a thousand
        // lost out of four thousand.
        self::assertGreaterThan(
            self::trend(39900, 40000, 40000)->weight(),
            self::trend(3000, 4000, 4000)->weight(),
        );
    }

    public function testALargerMovementAtTheSameVolumeWeighsMore(): void
    {
        self::assertGreaterThan(
            self::trend(3500, 4000, 4000)->weight(),
            self::trend(3000, 4000, 4000)->weight(),
        );
    }

    public function testAFallWeighsTheSameAsTheEquivalentRise(): void
    {
        self::assertSame(
            self::trend(3000, 4000, 4000)->weight(),
            self::trend(5000, 4000, 4000)->weight(),
        );
    }

    public function testNoMovementWeighsNothing(): void
    {
        self::assertSame(0.0, self::trend(4000, 4000, 4000)->weight());
    }

    public function testAMovementBehindNoTrafficAtAllWeighsNothing(): void
    {
        // log(1) is zero, so a volume of zero cannot outrank anything. It
        // also cannot produce a negative weight, which log(0) would.
        self::assertSame(0.0, self::trend(0, 0, 0)->weight());
    }

    private static function trend(int $value, int $previousValue, int $volume): PageTrend
    {
        return new PageTrend(2, $value, $previousValue, $volume, null, TrendDirection::Flat);
    }
}
