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

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Persistence\ImportStateRepository;
use KonradMichalik\Typo3PageMetrics\Service\ImportCoverage;
use PHPUnit\Framework\TestCase;

/**
 * ImportCoverageTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ImportCoverageTest extends TestCase
{
    private ImportCoverage $subject;

    protected function setUp(): void
    {
        // The repository is never reached: both mask() and covers() are told
        // the window, they do not look it up.
        $this->subject = new ImportCoverage($this->createMock(ImportStateRepository::class));
    }

    public function testARangeInsideTheImportedWindowCanBeComparedAgainst(): void
    {
        self::assertTrue($this->subject->covers($this->range('2026-08-01', '2026-08-31'), $this->range('2026-08-10', '2026-08-16')));
    }

    public function testARangeMatchingTheWindowExactlyIsCovered(): void
    {
        self::assertTrue($this->subject->covers($this->range('2026-08-10', '2026-08-16'), $this->range('2026-08-10', '2026-08-16')));
    }

    public function testARangeEntirelyBeforeTheImportedWindowIsNotCovered(): void
    {
        self::assertFalse($this->subject->covers($this->range('2026-08-10', '2026-08-31'), $this->range('2026-08-01', '2026-08-07')));
    }

    public function testARangeEntirelyAfterTheImportedWindowIsNotCovered(): void
    {
        self::assertFalse($this->subject->covers($this->range('2026-08-01', '2026-08-10'), $this->range('2026-08-20', '2026-08-27')));
    }

    public function testAPartiallyCoveredRangeIsRefusedToo(): void
    {
        // The honest default: a partial sum understates by exactly the amount
        // nobody can quantify, so there is no comparison to be made from it.
        self::assertFalse($this->subject->covers($this->range('2026-08-05', '2026-08-31'), $this->range('2026-08-01', '2026-08-07')));
        self::assertFalse($this->subject->covers($this->range('2026-08-01', '2026-08-20'), $this->range('2026-08-15', '2026-08-25')));
    }

    public function testEveryRangeIsCoveredWhenNothingHasEverBeenImported(): void
    {
        // Same claim as mask(): without import state the extension knows
        // nothing about its own window, so it withholds nothing either.
        self::assertTrue($this->subject->covers(null, $this->range('2026-08-01', '2026-08-07')));
    }

    public function testTheTimeOfDayDoesNotDecideACoveredDay(): void
    {
        // The window's ends are days, and a range built from "today" carries
        // whatever time the request happened at.
        $coverage = new DateRange(new DateTimeImmutable('2026-08-01 00:00:00'), new DateTimeImmutable('2026-08-07 00:00:00'));
        $range = new DateRange(new DateTimeImmutable('2026-08-01 09:30:00'), new DateTimeImmutable('2026-08-07 23:59:00'));

        self::assertTrue($this->subject->covers($coverage, $range));
    }

    private function range(string $from, string $until): DateRange
    {
        return new DateRange(new DateTimeImmutable($from), new DateTimeImmutable($until));
    }
}
