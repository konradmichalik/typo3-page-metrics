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
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use PHPUnit\Framework\TestCase;

/**
 * DateRangeTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class DateRangeTest extends TestCase
{
    public function testDaysYieldsOneEntryPerDayInclusive(): void
    {
        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-03'));

        $days = [];
        foreach ($range->days() as $day) {
            $days[] = $day->format('Y-m-d');
        }

        self::assertSame(['2026-08-01', '2026-08-02', '2026-08-03'], $days);
    }

    public function testDaysYieldsSingleEntryWhenFromEqualsUntil(): void
    {
        $date = new DateTimeImmutable('2026-08-01');
        $range = new DateRange($date, $date);

        self::assertSame(['2026-08-01'], iterator_to_array($this->formatted($range)));
    }

    /**
     * @return iterable<string>
     */
    private function formatted(DateRange $range): iterable
    {
        foreach ($range->days() as $day) {
            yield $day->format('Y-m-d');
        }
    }
}
