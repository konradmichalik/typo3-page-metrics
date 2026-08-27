<?php

declare(strict_types=1);

/*
 * This file is part of the "page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Domain\ValueObject;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricUnit, TrendDirection};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricTile;
use PHPUnit\Framework\TestCase;

/**
 * MetricTileTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MetricTileTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $tile = new MetricTile('matomo.pageviews', 'Page views', 'The number of times this page was viewed.', 'actions-view', '#0969da', MetricUnit::Count, 42, '42', ['2026-08-01' => 42], 12, TrendDirection::Up, 5, 'https://matomo.example.com/', '38');

        self::assertSame('matomo.pageviews', $tile->identifier);
        self::assertSame('Page views', $tile->label);
        self::assertSame('The number of times this page was viewed.', $tile->description);
        self::assertSame('actions-view', $tile->icon);
        self::assertSame('#0969da', $tile->color);
        self::assertSame(MetricUnit::Count, $tile->unit);
        self::assertSame(42, $tile->value);
        self::assertSame('42', $tile->formattedValue);
        self::assertSame(['2026-08-01' => 42], $tile->sparkline);
        self::assertSame(12, $tile->deltaPercent);
        self::assertSame(TrendDirection::Up, $tile->trend);
        self::assertSame(5, $tile->redirectSharePercent);
        self::assertSame('https://matomo.example.com/', $tile->externalLink);
        self::assertSame('38', $tile->previousFormattedValue);
    }

    public function testAllowsNullDeltaPercentForTheNewPeriodCase(): void
    {
        $tile = new MetricTile('matomo.pageviews', 'Page views', 'The number of times this page was viewed.', 'actions-view', '#0969da', MetricUnit::Count, 42, '42', [], null, TrendDirection::Flat, 0, null, '');

        self::assertNull($tile->deltaPercent);
        self::assertSame(TrendDirection::Flat, $tile->trend);
        self::assertSame(0, $tile->redirectSharePercent);
        self::assertNull($tile->externalLink);
        self::assertSame('', $tile->previousFormattedValue);
    }
}
