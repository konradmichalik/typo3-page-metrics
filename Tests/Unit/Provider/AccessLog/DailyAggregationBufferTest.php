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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Provider\AccessLog;

use KonradMichalik\Typo3PageMetrics\Provider\AccessLog\DailyAggregationBuffer;
use PHPUnit\Framework\TestCase;

/**
 * DailyAggregationBufferTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class DailyAggregationBufferTest extends TestCase
{
    private DailyAggregationBuffer $subject;

    protected function setUp(): void
    {
        $this->subject = new DailyAggregationBuffer();
    }

    public function testAdvanceToReportsWhetherTheWatermarkMoved(): void
    {
        // The provider checks for finished days only when this says yes, so
        // a wrong answer here either loses the flush or puts a sort back on
        // the hot path of every line.
        $subject = new DailyAggregationBuffer();

        self::assertTrue($subject->advanceTo('2026-08-02'));
        self::assertFalse($subject->advanceTo('2026-08-02'));
        self::assertFalse($subject->advanceTo('2026-08-01'));
        self::assertTrue($subject->advanceTo('2026-08-03'));
    }

    public function testNothingIsFinishedBeforeAnyDateHasBeenSeen(): void
    {
        self::assertSame([], $this->subject->finishedDates());
    }

    public function testADayIsNotFinishedWhileItIsStillTheNewestOne(): void
    {
        $this->subject->advanceTo('2026-10-10');
        $this->subject->addPageview('2026-10-10', '/about', 'visitor');

        self::assertSame([], $this->subject->finishedDates());
    }

    public function testTheDayBeforeTheNewestIsHeldBackAsSlack(): void
    {
        // One day of lag absorbs a log whose lines straddle midnight, or a
        // rotated file that ends a few entries into the next day.
        $this->subject->addPageview('2026-10-09', '/about', 'visitor');
        $this->subject->advanceTo('2026-10-10');

        self::assertSame([], $this->subject->finishedDates());
    }

    public function testADayTwoBehindTheNewestIsFinished(): void
    {
        $this->subject->addPageview('2026-10-08', '/about', 'visitor');
        $this->subject->addPageview('2026-10-09', '/about', 'visitor');
        $this->subject->advanceTo('2026-10-10');

        self::assertSame(['2026-10-08'], $this->subject->finishedDates());
    }

    public function testABotOnlyDayIsFinishedToo(): void
    {
        // A day can carry crawler hits and no human page view at all, and
        // would otherwise never be emitted.
        $this->subject->addBotHit('2026-10-08', 'search', 'https://example.com/gone');
        $this->subject->advanceTo('2026-10-10');

        self::assertSame(['2026-10-08'], $this->subject->finishedDates());
    }

    public function testForgettingADayDropsItsPageviewsVisitorsAndBotHits(): void
    {
        $this->subject->addPageview('2026-10-08', '/about', 'visitor');
        $this->subject->addBotHit('2026-10-08', 'search', 'https://example.com/about');

        $this->subject->forget('2026-10-08');

        self::assertSame([], $this->subject->bufferedDates());
        self::assertSame([], $this->subject->pageviewsOn('2026-10-08'));
        self::assertSame([], $this->subject->botHitsOn('2026-10-08'));
        self::assertSame(0, $this->subject->visitorCountOn('2026-10-08', '/about'));
    }

    public function testVisitorsAreCountedOncePerDistinctKey(): void
    {
        $this->subject->addPageview('2026-10-10', '/about', 'a');
        $this->subject->addPageview('2026-10-10', '/about', 'a');
        $this->subject->addPageview('2026-10-10', '/about', 'b');

        self::assertSame(['/about' => 3], $this->subject->pageviewsOn('2026-10-10'));
        self::assertSame(2, $this->subject->visitorCountOn('2026-10-10', '/about'));
    }

    public function testBufferedDatesComeBackOldestFirstRegardlessOfInsertionOrder(): void
    {
        $this->subject->addPageview('2026-10-10', '/about', 'visitor');
        $this->subject->addBotHit('2026-10-08', 'search', 'https://example.com/about');
        $this->subject->addPageview('2026-10-09', '/about', 'visitor');

        self::assertSame(['2026-10-08', '2026-10-09', '2026-10-10'], $this->subject->bufferedDates());
    }

    public function testAdvancingBackwardsDoesNotMoveTheNewestDate(): void
    {
        // Files are read oldest first, but a single file can still carry an
        // out-of-order line; it must not reopen days that were closed.
        $this->subject->addPageview('2026-10-08', '/about', 'visitor');
        $this->subject->advanceTo('2026-10-10');
        $this->subject->advanceTo('2026-10-09');

        self::assertSame(['2026-10-08'], $this->subject->finishedDates());
    }

    public function testADayIsClosedOnceItHasBeenForgotten(): void
    {
        $subject = new DailyAggregationBuffer();
        $subject->advanceTo('2026-08-01');
        $subject->addPageview('2026-08-01', '/a', 'v1');

        self::assertFalse($subject->isClosed('2026-08-01'));

        $subject->forget('2026-08-01');

        self::assertTrue($subject->isClosed('2026-08-01'));
        self::assertFalse($subject->isClosed('2026-08-02'));
    }
}
