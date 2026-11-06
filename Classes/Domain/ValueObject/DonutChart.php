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

namespace KonradMichalik\Typo3PageMetrics\Domain\ValueObject;

/**
 * DonutChart.
 *
 * A tile's breakdown, drawn: $cssGradient is a ready-made CSS conic-gradient()
 * value, computed here rather than in the template because Fluid has no
 * running total to accumulate one slice's start where the last one ended.
 * $legend repeats each part with the swatch colour its slice was drawn in, so
 * the two never fall out of step.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class DonutChart
{
    /**
     * @param list<array{label: string, formattedValue: string, color: string, hint?: string}> $legend
     */
    public function __construct(
        public string $cssGradient,
        public array $legend,
    ) {}
}
