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
 * DayMetricValue.
 *
 * One metric's figure for one single day, as the day panel prints it.
 *
 * $hasValue is the same distinction the sparkline draws as a gap: a day
 * outside the provider's imported window has no number, and printing the
 * zero the fact table would hand back is the one reading that is certainly
 * wrong. Fluid's boolean parser has no null literal, which is why this is a
 * flag rather than a nullable value (see Annotation::$isRange).
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class DayMetricValue
{
    public function __construct(
        public string $identifier,
        public string $label,
        public string $icon,
        public string $color,
        public string $formattedValue,
        public bool $hasValue,
    ) {}
}
