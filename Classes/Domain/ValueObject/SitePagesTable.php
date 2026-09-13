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

use function ceil;
use function count;
use function max;
use function min;

/**
 * SitePagesTable.
 *
 * The site-wide "pages" view of the backend module: one row per page, one
 * column per metric, sorted server side by whichever column was asked for.
 *
 * Server side and paged rather than sorted in the browser, because the table
 * is as long as the site and the page that is four hundredth by views can be
 * first by bounce rate. A sort that only reaches the rows already sent
 * would answer a different question than its own column header.
 *
 * $rows is one page of the result; $totalRows counts the whole of it, so the
 * heading can say what is being left out rather than implying that a
 * truncated list is the site.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SitePagesTable
{
    public const DIRECTION_ASCENDING = 'asc';
    public const DIRECTION_DESCENDING = 'desc';

    /**
     * Long enough that scrolling is the normal way through it and short
     * enough that a site with thousands of pages still renders. The pager
     * below the table is what makes the rest reachable.
     */
    public const PAGE_SIZE = 25;

    public int $pageCount;

    /**
     * The row numbers this page of the table actually shows, one-based, so
     * the prose above it can say which part of the whole is on screen.
     */
    public int $firstRowNumber;
    public int $lastRowNumber;

    public bool $hasPreviousPage;
    public bool $hasNextPage;
    public int $previousPageNumber;
    public int $nextPageNumber;

    /**
     * @param list<SitePagesColumn> $columns
     * @param list<SitePagesRow>    $rows
     */
    public function __construct(
        public bool $hasProvider,
        public array $columns,
        public array $rows,
        public string $sortKey,
        public string $sortDirection,
        public string $trendLabel,
        public string $currentRangeLabel,
        public string $previousRangeLabel,
        public int $totalRows,
        public int $pageNumber,
        public bool $previousPeriodCovered,
    ) {
        $this->pageCount = max(1, (int) ceil($totalRows / self::PAGE_SIZE));
        $this->firstRowNumber = 0 === $totalRows ? 0 : ($pageNumber - 1) * self::PAGE_SIZE + 1;
        // Guarded against an empty page rather than computed from the row
        // count alone, which would end an empty table at row minus one.
        $this->lastRowNumber = [] === $rows ? 0 : min($totalRows, $this->firstRowNumber + count($rows) - 1);
        $this->hasPreviousPage = $pageNumber > 1;
        $this->hasNextPage = $pageNumber < $this->pageCount;
        $this->previousPageNumber = max(1, $pageNumber - 1);
        $this->nextPageNumber = min($this->pageCount, $pageNumber + 1);
    }

    /**
     * No metric provider is configured for this site, which is an answer
     * about the site configuration rather than an empty period.
     */
    public static function withoutProvider(): self
    {
        return new self(false, [], [], '', self::DIRECTION_DESCENDING, '', '', '', 0, 1, true);
    }
}
