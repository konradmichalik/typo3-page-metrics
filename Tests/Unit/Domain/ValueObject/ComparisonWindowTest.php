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
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{ComparisonWindow, DateRange};
use PHPUnit\Framework\TestCase;

/**
 * ComparisonWindowTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ComparisonWindowTest extends TestCase
{
    public function testTheEventsOwnDayBelongsToNeitherSide(): void
    {
        $window = ComparisonWindow::around(new DateTimeImmutable('2026-06-15'), null, 14);

        self::assertSame('2026-06-01', $window->before->from->format('Y-m-d'));
        self::assertSame('2026-06-14', $window->before->until->format('Y-m-d'));
        self::assertSame('2026-06-16', $window->after->from->format('Y-m-d'));
        self::assertSame('2026-06-29', $window->after->until->format('Y-m-d'));
        self::assertSame(14, $window->windowDays);
    }

    public function testAMarkerSpanningDaysIsMeasuredFromTheDayItEnds(): void
    {
        // A three-week campaign has no "after" until it is over, and its own
        // days are the event, not its effect.
        $window = ComparisonWindow::around(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-21'), 14);

        self::assertSame('2026-05-18', $window->before->from->format('Y-m-d'));
        self::assertSame('2026-05-31', $window->before->until->format('Y-m-d'));
        self::assertSame('2026-06-22', $window->after->from->format('Y-m-d'));
        self::assertSame('2026-07-05', $window->after->until->format('Y-m-d'));
    }

    public function testCountsOnlyTheDaysOfTheAfterWindowThatWereActuallyImported(): void
    {
        $window = ComparisonWindow::around(new DateTimeImmutable('2026-06-15'), null, 14);
        $coverage = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-06-20'));

        self::assertSame(5, $window->availableAfterDays($coverage, new DateTimeImmutable('2026-06-20')));
        self::assertSame(14, $window->availableBeforeDays($coverage, new DateTimeImmutable('2026-06-20')));
        self::assertFalse($window->isComplete($coverage, new DateTimeImmutable('2026-06-20')));
    }

    public function testAnAfterWindowThatHasNotHappenedYetIsNotDataThatIsMissing(): void
    {
        // No import state at all: the last complete day is all there is to
        // measure against, and a marker from three days ago simply has no
        // fourteen days after it yet.
        $window = ComparisonWindow::around(new DateTimeImmutable('2026-06-15'), null, 14);

        self::assertSame(3, $window->availableAfterDays(null, new DateTimeImmutable('2026-06-18')));
        self::assertFalse($window->isComplete(null, new DateTimeImmutable('2026-06-18')));
    }

    public function testAHistoryThatStartsInsideTheBeforeWindowMakesTheComparisonIncomplete(): void
    {
        $window = ComparisonWindow::around(new DateTimeImmutable('2026-06-15'), null, 14);
        $coverage = new DateRange(new DateTimeImmutable('2026-06-10'), new DateTimeImmutable('2026-07-30'));

        self::assertSame(5, $window->availableBeforeDays($coverage, new DateTimeImmutable('2026-07-30')));
        self::assertSame(14, $window->availableAfterDays($coverage, new DateTimeImmutable('2026-07-30')));
        self::assertFalse($window->isComplete($coverage, new DateTimeImmutable('2026-07-30')));
    }

    public function testBothSidesFullyImportedIsTheOnlyCompleteCase(): void
    {
        $window = ComparisonWindow::around(new DateTimeImmutable('2026-06-15'), null, 14);
        $coverage = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-07-30'));

        self::assertTrue($window->isComplete($coverage, new DateTimeImmutable('2026-07-30')));
    }

    public function testAWindowEntirelyBeyondWhatIsKnownCountsNoDaysRatherThanNegativeOnes(): void
    {
        $window = ComparisonWindow::around(new DateTimeImmutable('2026-06-15'), null, 14);

        self::assertSame(0, $window->availableAfterDays(null, new DateTimeImmutable('2026-06-15')));
    }
}
