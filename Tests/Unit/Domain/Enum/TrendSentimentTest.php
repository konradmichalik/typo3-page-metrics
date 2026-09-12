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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Domain\Enum;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricPolarity, TrendDirection, TrendSentiment};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TrendSentimentTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TrendSentimentTest extends TestCase
{
    /**
     * @return iterable<string, array{TrendDirection, MetricPolarity, TrendSentiment}>
     */
    public static function directionAndPolarityProvider(): iterable
    {
        yield 'rising page views improved' => [TrendDirection::Up, MetricPolarity::HigherIsBetter, TrendSentiment::Positive];
        yield 'falling page views declined' => [TrendDirection::Down, MetricPolarity::HigherIsBetter, TrendSentiment::Negative];
        yield 'rising bounce rate declined' => [TrendDirection::Up, MetricPolarity::LowerIsBetter, TrendSentiment::Negative];
        yield 'falling bounce rate improved' => [TrendDirection::Down, MetricPolarity::LowerIsBetter, TrendSentiment::Positive];
        yield 'flat is never a judgement' => [TrendDirection::Flat, MetricPolarity::HigherIsBetter, TrendSentiment::Neutral];
        yield 'flat stays neutral for an inverted metric' => [TrendDirection::Flat, MetricPolarity::LowerIsBetter, TrendSentiment::Neutral];
        yield 'a rise in a neutral metric is not an improvement' => [TrendDirection::Up, MetricPolarity::Neutral, TrendSentiment::Neutral];
        yield 'a drop in a neutral metric is not a decline' => [TrendDirection::Down, MetricPolarity::Neutral, TrendSentiment::Neutral];
    }

    #[DataProvider('directionAndPolarityProvider')]
    public function testDerivesSentimentFromDirectionAndPolarity(TrendDirection $direction, MetricPolarity $polarity, TrendSentiment $expected): void
    {
        self::assertSame($expected, TrendSentiment::of($direction, $polarity));
    }
}
