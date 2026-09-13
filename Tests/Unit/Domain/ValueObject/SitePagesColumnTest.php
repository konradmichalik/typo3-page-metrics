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

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{SitePagesColumn, SitePagesTable};
use PHPUnit\Framework\TestCase;

/**
 * SitePagesColumnTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SitePagesColumnTest extends TestCase
{
    public function testTheSortedColumnAnnouncesItsOrderAndOffersTheOther(): void
    {
        $descending = $this->column(true, SitePagesTable::DIRECTION_DESCENDING);
        $ascending = $this->column(true, SitePagesTable::DIRECTION_ASCENDING);

        self::assertSame('descending', $descending->ariaSort);
        self::assertSame(SitePagesTable::DIRECTION_ASCENDING, $descending->nextDirection);
        self::assertSame('ascending', $ascending->ariaSort);
        self::assertSame(SitePagesTable::DIRECTION_DESCENDING, $ascending->nextDirection);
    }

    public function testAnUnsortedColumnClaimsNoOrderAndOpensAtDescending(): void
    {
        // The table's own direction is deliberately the ascending one here:
        // a column that is not sorted must not report the order of the
        // column that is.
        $column = $this->column(false, SitePagesTable::DIRECTION_ASCENDING);

        self::assertSame('none', $column->ariaSort);
        self::assertSame(SitePagesTable::DIRECTION_DESCENDING, $column->nextDirection);
    }

    private function column(bool $isSorted, string $sortDirection): SitePagesColumn
    {
        return new SitePagesColumn('matomo.pageviews', 'Page views', 'How often the page was viewed.', $isSorted, $sortDirection);
    }
}
