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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\TrendDirection;

use function round;

/**
 * MetricTrend.
 *
 * How a period's value compares with the one before it: which way it moved,
 * and by how much where that can be said at all.
 *
 * Extracted because the page module's tiles and the dashboard's trend lists
 * each carried an identical copy of this arithmetic, returned as an untyped
 * two-element array that every caller had to destructure in the right order.
 * Two copies of one rule is one copy too many when the rule is "what counts
 * as new".
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MetricTrend
{
    /**
     * @param int|null $deltaPercent null when the previous period was zero and this one is not: there is no
     *                               percentage base, so the change is reported as "new" rather than as a false
     *                               infinity
     */
    private function __construct(
        public ?int $deltaPercent,
        public TrendDirection $direction,
    ) {}

    /**
     * The previous period cannot be compared against at all, because the
     * import never covered it (see ImportCoverage::covers()). Not the same
     * as a previous period of zero: that one was measured.
     */
    public static function none(): self
    {
        return new self(null, TrendDirection::Flat);
    }

    public static function between(int $value, int $previousValue): self
    {
        if (0 === $previousValue) {
            // No meaningful percentage base: value > 0 is "new" (null percent,
            // still an upward trend), value === 0 is genuinely flat.
            return new self(null, $value > 0 ? TrendDirection::Up : TrendDirection::Flat);
        }

        return new self(
            (int) round(($value - $previousValue) / $previousValue * 100),
            match (true) {
                $value > $previousValue => TrendDirection::Up,
                $value < $previousValue => TrendDirection::Down,
                default => TrendDirection::Flat,
            },
        );
    }
}
