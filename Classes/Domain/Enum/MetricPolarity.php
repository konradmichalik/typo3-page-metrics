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
 * MetricPolarity.
 *
 * Whether a rise in a metric is an improvement. A TrendDirection alone
 * cannot say that: page views going up is good, a bounce rate going up is
 * bad, and an average time on page going up is neither without knowing what
 * the page is for. Only the provider that defines a metric knows, so this
 * sits on MetricDefinition/RateMetricDefinition rather than being guessed
 * from the identifier.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
enum MetricPolarity: string
{
    case HigherIsBetter = 'higher_is_better';
    case LowerIsBetter = 'lower_is_better';
    case Neutral = 'neutral';
}
