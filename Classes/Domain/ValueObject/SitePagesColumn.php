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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;

/**
 * SitePagesColumn.
 *
 * One sortable metric column of the site-wide pages table.
 *
 * It carries what a sort link needs but never the link itself: building the
 * URI in the template keeps this object free of the backend router, and the
 * module's route already knows its own page, period and view.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SitePagesColumn
{
    /**
     * The value of the column header's aria-sort, which is "none" on every
     * column but the one actually sorted: aria-sort on more than one header
     * of the same table claims two orderings at once.
     */
    public string $ariaSort;

    /**
     * What this column's own header link asks for next. Clicking the sorted
     * column flips it; clicking any other opens at descending, because "most
     * of it" is the question a metric column is opened with.
     */
    public string $nextDirection;

    /**
     * $unit is what the raw value in this column is counted in, and it is
     * null only on the decay column, which measures days rather than
     * anything a provider defined. The export reads it here rather than
     * being told per row: the unit belongs to the column, and repeating it
     * on every row of a site-wide file is a fact restated a thousand times.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public bool $isSorted,
        string $sortDirection,
        public ?MetricUnit $unit,
    ) {
        $this->ariaSort = match (true) {
            !$isSorted => 'none',
            SitePagesTable::DIRECTION_ASCENDING === $sortDirection => 'ascending',
            default => 'descending',
        };
        $this->nextDirection = $isSorted && SitePagesTable::DIRECTION_DESCENDING === $sortDirection
            ? SitePagesTable::DIRECTION_ASCENDING
            : SitePagesTable::DIRECTION_DESCENDING;
    }

    /**
     * The decay column, which sorts like any other but is not a metric.
     *
     * Label and description are empty on purpose, and that is the one thing
     * this column does differently: every other header repeats a provider's
     * own words, while "decay" is a word this view invents and therefore
     * writes itself, in the template's translation next to the prose that
     * explains it. Reusing the class rather than inventing a second one is
     * what keeps aria-sort and the offered direction from drifting apart
     * between the metric headers and this one.
     */
    public static function forDecay(bool $isSorted, string $sortDirection): self
    {
        return new self(SitePagesTable::SORT_DECAY, '', '', $isSorted, $sortDirection, null);
    }
}
