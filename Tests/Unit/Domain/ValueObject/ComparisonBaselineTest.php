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
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{ComparisonBaseline, DateRange};
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * ComparisonBaselineTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ComparisonBaselineTest extends TestCase
{
    public function testTheDefaultIsThePeriodImmediatelyBefore(): void
    {
        // What every other surface of this extension already compares
        // against, so the view opens on the answer the reader knows.
        $reference = ComparisonBaseline::default()->reference($this->range('2026-03-15', '2026-04-13'), 30);

        self::assertSame('previous', ComparisonBaseline::default()->key);
        self::assertSame('2026-02-13', $reference->from->format('Y-m-d'));
        self::assertSame('2026-03-14', $reference->until->format('Y-m-d'));
    }

    public function testTheSamePeriodOneYearEarlier(): void
    {
        $reference = ComparisonBaseline::fromKey('year')->reference($this->range('2026-03-15', '2026-04-13'), 30);

        self::assertSame('2025-03-15', $reference->from->format('Y-m-d'));
        self::assertSame('2025-04-13', $reference->until->format('Y-m-d'));
    }

    public function testTheSamePeriodOneQuarterEarlier(): void
    {
        $reference = ComparisonBaseline::fromKey('quarter')->reference($this->range('2026-06-01', '2026-06-30'), 30);

        self::assertSame('2026-03-01', $reference->from->format('Y-m-d'));
        self::assertSame('2026-03-30', $reference->until->format('Y-m-d'));
    }

    public function testAMonthBeforeTheThirtyFirstIsTheLastDayOfThatMonth(): void
    {
        // PHP's own "-1 month" on 2026-03-31 lands on 2026-03-03, which is
        // inside the period it is supposed to be the baseline for.
        $reference = ComparisonBaseline::fromKey('month')->reference($this->range('2026-03-31', '2026-04-29'), 30);

        self::assertSame('2026-02-28', $reference->from->format('Y-m-d'));
        self::assertSame('2026-03-29', $reference->until->format('Y-m-d'));
    }

    public function testEveryBaselineYieldsARangeOfTheSameLengthAsTheOneItIsComparedWith(): void
    {
        // Two totals over windows of different lengths are not comparable at
        // all, and nothing downstream would notice the difference.
        $current = $this->range('2026-05-31', '2026-06-06');

        foreach (ComparisonBaseline::all() as $baseline) {
            $reference = $baseline->reference($current, 7);

            self::assertSame(6, $reference->from->diff($reference->until)->days, $baseline->key);
        }
    }

    public function testAnUnknownKeyFallsBackToTheDefaultRatherThanFailing(): void
    {
        // The key arrives from moduleData, which is a stored user preference
        // and survives a release that renames an option.
        self::assertSame('previous', ComparisonBaseline::fromKey('whatever')->key);
        self::assertSame('previous', ComparisonBaseline::fromKey(null)->key);
    }

    public function testTheOptionsAreOfferedInWideningOrder(): void
    {
        self::assertSame(
            ['previous', 'month', 'quarter', 'year'],
            array_map(static fn (ComparisonBaseline $baseline): string => $baseline->key, ComparisonBaseline::all()),
        );
    }

    private function range(string $from, string $until): DateRange
    {
        return new DateRange(new DateTimeImmutable($from), new DateTimeImmutable($until));
    }
}
