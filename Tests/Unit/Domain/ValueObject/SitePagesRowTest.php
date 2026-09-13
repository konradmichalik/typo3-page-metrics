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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricPolarity, TrendDirection, TrendSentiment};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{SitePagesCell, SitePagesRow};
use PHPUnit\Framework\TestCase;

/**
 * SitePagesRowTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SitePagesRowTest extends TestCase
{
    public function testTellsAPageChangedTodayFromOneWithNoRecordedChange(): void
    {
        // Nothing else in the row can carry this apart: a template condition
        // on the day count reads zero days and no record as the same falsy
        // value, and they are different answers about the page.
        $changedToday = $this->row(0);
        $neverRecorded = $this->row(null);

        self::assertTrue($changedToday->hasAge);
        self::assertSame(0, $changedToday->daysSinceChange);
        self::assertFalse($neverRecorded->hasAge);
    }

    public function testIsNotDecayingUnlessItWasSaidToBe(): void
    {
        self::assertFalse($this->row(900)->isDecaying);
        self::assertTrue($this->row(900, true)->isDecaying);
    }

    public function testStillReportsTheMovementItAlwaysDid(): void
    {
        $row = $this->row(900, true);

        self::assertFalse($row->isNew);
        self::assertFalse($row->hasNoComparison);
        self::assertSame(TrendSentiment::Negative, $row->sentiment);
    }

    private function row(?int $daysSinceChange, bool $isDecaying = false): SitePagesRow
    {
        return new SitePagesRow(
            2,
            'About',
            [new SitePagesCell(true, '42')],
            -80,
            TrendDirection::Down,
            MetricPolarity::HigherIsBetter,
            true,
            $daysSinceChange,
            $isDecaying,
        );
    }
}
