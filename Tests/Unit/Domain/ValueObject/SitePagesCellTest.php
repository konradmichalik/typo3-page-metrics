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

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\SitePagesCell;
use PHPUnit\Framework\TestCase;

/**
 * SitePagesCellTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SitePagesCellTest extends TestCase
{
    public function testAnOrdinaryCellCarriesNeitherAShapeNorAChange(): void
    {
        $cell = new SitePagesCell(true, '42', false, null);

        self::assertFalse($cell->showsTrend);
        self::assertFalse($cell->hasSparkline);
        self::assertSame([], $cell->sparkline);
    }

    public function testCarriesTheChangeEvenWithNoShapeToDraw(): void
    {
        // The page that recorded nothing at all this period: there is no line
        // to draw and the fall to zero is exactly what the reader came for, so
        // one flag cannot answer both questions.
        $cell = new SitePagesCell(true, '0', true, null);

        self::assertTrue($cell->showsTrend);
        self::assertFalse($cell->hasSparkline);
    }

    public function testWrapsTheOneSeriesInTheListTheRendererReads(): void
    {
        $cell = new SitePagesCell(true, '42', true, [
            'label' => 'Page views',
            'color' => '#1a73e8',
            'unit' => 'count',
            'values' => ['2026-09-12' => 9, '2026-09-13' => null],
        ]);

        self::assertTrue($cell->hasSparkline);
        self::assertSame('Page views', $cell->sparkline[0]['label']);
    }
}
