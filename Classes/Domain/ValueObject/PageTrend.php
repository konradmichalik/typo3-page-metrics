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

use function abs;
use function log;
use function max;

/**
 * PageTrend.
 *
 * One page's movement on one metric between two periods, before anything has
 * been decided about whether it is worth showing.
 *
 * An object rather than the array shape this used to be: ranking needs the
 * previous value and the volume as well as the current value, and a six-key
 * array restated in every method's docblock is a list nobody keeps in sync.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PageTrend
{
    /**
     * @param int      $volume       how much traffic stands behind the movement, which is the value itself for a
     *                               count and the denominator for a rate: a bounce rate that swung twenty points
     *                               on four visits is not the same event as one that swung five on four thousand
     * @param int|null $deltaPercent null where the previous period was zero, so there is no percentage base
     */
    public function __construct(
        public int $pageUid,
        public int $value,
        public int $previousValue,
        public int $volume,
        public ?int $deltaPercent,
        public TrendDirection $direction,
    ) {}

    /**
     * How much this movement is worth attending to, for ordering a list of
     * them against each other.
     *
     * The size of the change weighted by the log of the volume behind it.
     * Plain size would let the busiest page own the list whatever it did,
     * and plain percentage is what MetricSignificance exists to stop ranking
     * by. The log sits between the two, so a page ten times busier counts
     * for more without counting for ten times more.
     */
    public function weight(): float
    {
        return abs($this->value - $this->previousValue) * log(1 + max(0, $this->volume));
    }
}
