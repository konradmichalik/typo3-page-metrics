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

namespace KonradMichalik\Typo3PageMetrics\Domain\Enum;

/**
 * TrendSentiment.
 *
 * "Got better / got worse", as opposed to TrendDirection's "went up / went
 * down". The two are only the same thing for a metric where higher is
 * better, which is why colour is bound to this and the arrow icon stays
 * bound to TrendDirection.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
enum TrendSentiment: string
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Neutral = 'neutral';

    public static function of(TrendDirection $direction, MetricPolarity $polarity): self
    {
        if (TrendDirection::Flat === $direction || MetricPolarity::Neutral === $polarity) {
            return self::Neutral;
        }

        $isImprovement = MetricPolarity::HigherIsBetter === $polarity
            ? TrendDirection::Up === $direction
            : TrendDirection::Down === $direction;

        return $isImprovement ? self::Positive : self::Negative;
    }
}
