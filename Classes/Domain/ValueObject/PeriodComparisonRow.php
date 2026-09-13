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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{TrendDirection, TrendSentiment};

/**
 * PeriodComparisonRow.
 *
 * One metric over two periods: what it was in each, how far apart the two
 * are, and whether that distance is worth reading as a change at all.
 *
 * $isSignificant is the only field that is new here. Everything else is the
 * page view's tile row under another name, deliberately: a reader who has
 * learned what "new" and a missing badge mean on a tile must not have to
 * learn it a second time three clicks away.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PeriodComparisonRow
{
    /**
     * Neither period can be compared against the other, because at least one
     * of them is made of days nobody imported. The same fact and the same
     * name as MetricTile::$hasNoComparison, so both surfaces say it once and
     * say it identically.
     */
    public bool $hasNoComparison;

    /**
     * Nothing to take a percentage of: the reference period was measured and
     * held nothing. Same flag, same reason as MetricTile::$isNew, and
     * distinct from $hasNoComparison for the same reason it is there.
     */
    public bool $isNew;

    /**
     * @param string $formattedReferenceValue empty where the two periods cannot be compared, since a number
     *                                        printed beside one it may not be held against is read as a
     *                                        comparison whatever the column says
     * @param string $formattedDifference     signed, and empty for the same reason
     * @param bool   $isSignificant           whether the movement clears the metric's own MetricSignificance,
     *                                        the same rule and the same thresholds the trend widget ranks by
     */
    public function __construct(
        public string $identifier,
        public string $label,
        public string $description,
        public string $icon,
        public string $color,
        public string $formattedCurrentValue,
        public string $formattedReferenceValue,
        public string $formattedDifference,
        public ?int $deltaPercent,
        public TrendDirection $direction,
        public TrendSentiment $sentiment,
        public bool $isSignificant,
        bool $isComparable,
    ) {
        $this->hasNoComparison = !$isComparable;
        $this->isNew = $isComparable && null === $deltaPercent;
    }
}
