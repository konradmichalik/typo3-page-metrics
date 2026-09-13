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

    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public bool $isSorted,
        string $sortDirection,
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
}
