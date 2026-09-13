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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricPolarity, TrendDirection};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{SitePagesCell, SitePagesRow, SitePagesTable};
use PHPUnit\Framework\TestCase;

use function array_map;
use function range;

/**
 * SitePagesTableTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SitePagesTableTest extends TestCase
{
    public function testCountsThePagesTheWholeResultNeeds(): void
    {
        $table = $this->table(31, 1, SitePagesTable::PAGE_SIZE);

        self::assertSame(2, $table->pageCount);
        self::assertSame(1, $table->firstRowNumber);
        self::assertSame(25, $table->lastRowNumber);
        self::assertFalse($table->hasPreviousPage);
        self::assertTrue($table->hasNextPage);
        self::assertSame(2, $table->nextPageNumber);
    }

    public function testNumbersTheRowsOfALaterPageAgainstTheWholeResult(): void
    {
        $table = $this->table(31, 2, 6);

        self::assertSame(26, $table->firstRowNumber);
        self::assertSame(31, $table->lastRowNumber);
        self::assertTrue($table->hasPreviousPage);
        self::assertFalse($table->hasNextPage);
        self::assertSame(1, $table->previousPageNumber);
    }

    public function testAnEmptyResultIsStillOnePageAndNumbersNoRows(): void
    {
        $table = $this->table(0, 1, 0);

        self::assertSame(1, $table->pageCount);
        self::assertSame(0, $table->firstRowNumber);
        self::assertSame(0, $table->lastRowNumber);
        self::assertFalse($table->hasPreviousPage);
        self::assertFalse($table->hasNextPage);
    }

    public function testWithoutProviderCarriesNothingToDraw(): void
    {
        $table = SitePagesTable::withoutProvider();

        self::assertFalse($table->hasProvider);
        self::assertSame([], $table->columns);
        self::assertSame([], $table->rows);
        self::assertSame(0, $table->totalRows);
        self::assertSame(1, $table->pageCount);
    }

    private function table(int $totalRows, int $pageNumber, int $rowCount): SitePagesTable
    {
        $rows = array_map(
            static fn (int $pageUid): SitePagesRow => new SitePagesRow(
                $pageUid,
                'Page '.$pageUid,
                [new SitePagesCell(true, '1')],
                0,
                TrendDirection::Flat,
                MetricPolarity::HigherIsBetter,
                true,
            ),
            0 === $rowCount ? [] : range(1, $rowCount),
        );

        return new SitePagesTable(true, [], $rows, 'matomo.pageviews', SitePagesTable::DIRECTION_DESCENDING, 'Page views', 'a', 'b', $totalRows, $pageNumber, true);
    }
}
