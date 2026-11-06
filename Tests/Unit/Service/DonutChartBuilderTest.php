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

use KonradMichalik\Typo3PageMetrics\Service\DonutChartBuilder;
use PHPUnit\Framework\TestCase;

/**
 * DonutChartBuilderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class DonutChartBuilderTest extends TestCase
{
    private DonutChartBuilder $subject;

    protected function setUp(): void
    {
        $this->subject = new DonutChartBuilder();
    }

    public function testDrawsOneSliceForEachPartInPercentOfTheirTotal(): void
    {
        $chart = $this->subject->build([
            ['label' => 'GPTBot', 'formattedValue' => '75', 'value' => 75],
            ['label' => 'ClaudeBot', 'formattedValue' => '25', 'value' => 25],
        ]);

        self::assertNotNull($chart);
        self::assertSame(
            'conic-gradient(var(--pm-donut-color-0) 0% 75%, var(--pm-donut-color-1) 75% 100%)',
            $chart->cssGradient,
        );
    }

    public function testTheLegendRepeatsEachPartWithItsSliceColour(): void
    {
        $chart = $this->subject->build([
            ['label' => 'GPTBot', 'formattedValue' => '20', 'value' => 20, 'hint' => "OpenAI's crawler."],
            ['label' => 'ClaudeBot', 'formattedValue' => '10', 'value' => 10],
        ]);

        self::assertNotNull($chart);
        self::assertSame([
            ['label' => 'GPTBot', 'formattedValue' => '20', 'color' => 'var(--pm-donut-color-0)', 'hint' => "OpenAI's crawler."],
            ['label' => 'ClaudeBot', 'formattedValue' => '10', 'color' => 'var(--pm-donut-color-1)'],
        ], $chart->legend);
    }

    public function testGivesTheLargestBuiltInBreakdownADistinctColourPerPart(): void
    {
        // Five named crawlers, "Others (n)" and "Unattributed": what
        // TileBreakdownBuilder::byAgent() returns at most.
        $breakdown = [];

        for ($i = 0; $i < 7; ++$i) {
            $breakdown[] = ['label' => 'Bot'.$i, 'formattedValue' => '1', 'value' => 1];
        }

        $chart = $this->subject->build($breakdown);

        self::assertNotNull($chart);
        self::assertCount(7, array_unique(array_column($chart->legend, 'color')));
    }

    public function testCyclesThroughThePaletteRatherThanRunningOutOfColours(): void
    {
        $breakdown = [];

        for ($i = 0; $i < 9; ++$i) {
            $breakdown[] = ['label' => 'Bot'.$i, 'formattedValue' => '1', 'value' => 1];
        }

        $chart = $this->subject->build($breakdown);

        self::assertNotNull($chart);
        self::assertSame('var(--pm-donut-color-0)', $chart->legend[7]['color']);
        self::assertSame('var(--pm-donut-color-1)', $chart->legend[8]['color']);
    }

    public function testIsNullWhenAnyPartCarriesNoValueToSizeASliceBy(): void
    {
        // A listener building its own breakdown (see ModifyMetricTilesEvent)
        // is not required to carry a raw $value on every part - a donut
        // missing one part's size would understate what "Made up of"
        // actually claims, so it draws nothing rather than draw it wrong.
        $chart = $this->subject->build([
            ['label' => 'Direct', 'formattedValue' => '60', 'value' => 60],
            ['label' => 'Search', 'formattedValue' => '40'],
        ]);

        self::assertNull($chart);
    }

    public function testIsNullForAnEmptyBreakdown(): void
    {
        self::assertNull($this->subject->build([]));
    }

    public function testIsNullWhenEveryValueIsZeroOrLess(): void
    {
        $chart = $this->subject->build([
            ['label' => 'Direct', 'formattedValue' => '0', 'value' => 0],
            ['label' => 'Search', 'formattedValue' => '0', 'value' => 0],
        ]);

        self::assertNull($chart);
    }

    public function testSkipsAPartAtZeroButStillDrawsTheOthers(): void
    {
        $chart = $this->subject->build([
            ['label' => 'Direct', 'formattedValue' => '0', 'value' => 0],
            ['label' => 'Search', 'formattedValue' => '10', 'value' => 10],
        ]);

        self::assertNotNull($chart);
        self::assertSame(['Search'], array_column($chart->legend, 'label'));
    }
}
