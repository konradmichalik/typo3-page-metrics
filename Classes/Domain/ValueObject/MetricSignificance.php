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

use function abs;

/**
 * MetricSignificance.
 *
 * How much a metric has to move before the movement is worth showing.
 *
 * Both thresholds have to be cleared, which is the whole point. A percentage
 * on its own makes a page going from 2 views to 4 a 100% riser that outranks
 * a page which lost a thousand, and an absolute figure on its own buries a
 * small page that genuinely doubled. The same pairing is what Matomo's
 * Generated Insights plugin uses.
 *
 * The absolute threshold is expressed in the metric's own unit, so it is
 * views for a count and percentage points for a rate. A bounce rate moving
 * from 30% to 33% has an absolute change of 3, not of 10.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MetricSignificance
{
    /**
     * A tenth, and no floor. Deliberately permissive: it is what applies to
     * every metric whose provider says nothing, and a default that hides
     * movement on a small site is worse than one that shows a little noise
     * on a large one. A provider that knows its own volumes should say so.
     */
    private const DEFAULT_RELATIVE = 0.1;

    /**
     * @param float $relative share of the previous period's value, so 0.1 is "at least a tenth"
     * @param int   $absolute the metric's own unit, 0 for no floor at all
     */
    public function __construct(
        public float $relative,
        public int $absolute,
    ) {}

    public static function default(): self
    {
        return new self(self::DEFAULT_RELATIVE, 0);
    }

    /**
     * A previous value of zero never qualifies. There is no share of nothing,
     * and the caller has already decided such a page is "new" rather than a
     * mover (see MetricTrend).
     */
    public function clears(int $value, int $previousValue): bool
    {
        if (0 === $previousValue) {
            return false;
        }

        $change = abs($value - $previousValue);

        return $change >= $this->absolute && $change / abs($previousValue) >= $this->relative;
    }
}
