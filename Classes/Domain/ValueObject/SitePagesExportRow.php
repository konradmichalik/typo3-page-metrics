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
 * SitePagesExportRow.
 *
 * One page of the site-wide table on its way out of the module, in the same
 * order the table shows it, with the numbers instead of the words for them.
 *
 * The difference from SitePagesRow is the whole point: a cell of the table
 * carries "3:03" because that is what a reader reads, and an export carries
 * 183 because the recipient will divide it by something. A display string
 * used as a datum is what §4.8 of the module spec rules out, so the two
 * shapes are separate rather than one shape parsed back.
 *
 * $values is keyed by metric identifier rather than positional, unlike
 * SitePagesCell: an export has no Fluid path to satisfy, and a file whose
 * columns are matched by position to a header row written somewhere else is
 * a file that silently shifts the day a metric is added.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SitePagesExportRow
{
    /**
     * @param array<string, int|null> $values          metric identifier => raw value in that metric's own unit (see
     *                                                 SitePagesColumn::$unit), null where the metric was never recorded for
     *                                                 this page and the absence is not a zero
     * @param int|null                $deltaPercent    movement of the sorted metric, null where there was no previous value
     *                                                 to divide by, which reads as "new"
     * @param bool                    $hasComparison   false where the period before this one was never imported, so
     *                                                 $deltaPercent is absent because nothing could be compared rather than
     *                                                 because the page is new
     * @param int|null                $daysSinceChange whole days since the page or its content was last edited, null where
     *                                                 the record carries no timestamp at all
     */
    public function __construct(
        public int $pageUid,
        public string $title,
        public array $values,
        public ?int $deltaPercent,
        public bool $hasComparison,
        public ?int $daysSinceChange,
        public bool $isDecaying,
    ) {}
}
