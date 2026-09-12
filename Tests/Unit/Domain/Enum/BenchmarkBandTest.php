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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Domain\Enum;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\BenchmarkBand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * BenchmarkBandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class BenchmarkBandTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, list<int>, BenchmarkBand}>
     */
    public static function fieldProvider(): iterable
    {
        $spread = [10, 20, 30, 40, 50];

        yield 'the highest page is in the top fifth' => [50, 30, $spread, BenchmarkBand::TopFifth];
        yield 'the lowest page is in the bottom fifth' => [10, 30, $spread, BenchmarkBand::BottomFifth];
        yield 'the middle page is the typical one' => [30, 30, $spread, BenchmarkBand::AtMedian];
        yield 'a page above the middle is above it' => [40, 30, $spread, BenchmarkBand::AboveMedian];
        yield 'a page below the middle is below it' => [20, 30, $spread, BenchmarkBand::BelowMedian];
    }

    /**
     * @param list<int> $values
     */
    #[DataProvider('fieldProvider')]
    public function testPlacesAPageInTheFieldItBelongsTo(int $value, int $median, array $values, BenchmarkBand $expected): void
    {
        self::assertSame($expected, BenchmarkBand::of($value, $median, $values));
    }

    public function testTiesCountHalfSoAMostlyFlatFieldDoesNotPromoteEveryone(): void
    {
        // Four pages on 5 and one on 9. Counting the ties in full would put
        // all four of the tied pages in the top fifth; counting none of them
        // would put all four in the bottom one. Neither says anything about
        // a page, only about the sort order.
        $field = [5, 5, 5, 5, 9];

        self::assertSame(BenchmarkBand::AtMedian, BenchmarkBand::of(5, 5, $field));
        self::assertSame(BenchmarkBand::TopFifth, BenchmarkBand::of(9, 5, $field));
    }

    public function testTheMedianWinsOverTheShareWhenTheyDisagree(): void
    {
        // A page on the median value is the typical page whatever its rank
        // among the ties works out to, which is the question being asked.
        $field = [1, 5, 5, 5, 100];

        self::assertSame(BenchmarkBand::AtMedian, BenchmarkBand::of(5, 5, $field));
    }
}
