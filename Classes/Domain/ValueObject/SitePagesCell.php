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
 * SitePagesCell.
 *
 * One metric's value for one page in the site-wide pages table, in the same
 * order as the table's columns rather than keyed by metric identifier: a
 * metric identifier contains dots, and Fluid reads a dot in a variable path
 * as a step into the object, so a keyed map could not be read back out in
 * the template at all.
 *
 * $hasValue is false only where the metric was never recorded for this page
 * and is not therefore a zero. That distinction only arises for a rate: a
 * count with no stored row genuinely is zero, because the importers write no
 * row for a page that had no traffic (see TopPage::$secondaryFormattedValue),
 * while a ratio with no denominator was divided by nothing and is not a low
 * value but no value.
 *
 * One cell of every row carries more than its figure: the metric the table
 * is sorted by gets the daily shape of the period and the movement against
 * the period before it, in the column of the metric both are about. That is
 * what replaced a trend column of its own, and it is why the table lost a
 * column rather than gaining one.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SitePagesCell
{
    /**
     * The payload sparkline.js reads from data-sparkline: a list of series,
     * of which this cell ever has one. Wrapped here rather than at the call
     * site because the list is the renderer's shape and not the builder's
     * (see initializeStaticCharts()), and empty on every cell that draws no
     * shape at all.
     *
     * @var list<array{label: string, color: string, unit: string, values: array<string, int|null>}>
     */
    public array $sparkline;

    public bool $hasSparkline;

    /**
     * $showsTrend and $hasSparkline are two questions, not one. A page that
     * recorded nothing at all this period has no shape to draw and still has
     * a movement worth stating, which is the -100% on a page that stopped: a
     * single flag would either drop that badge or promise a line that cannot
     * be drawn.
     *
     * A day outside the imported window is null rather than zero in $series,
     * and the renderer breaks the line there instead of diving to the
     * baseline (see ImportCoverage::mask()).
     *
     * @param array{label: string, color: string, unit: string, values: array<string, int|null>}|null $series
     */
    public function __construct(
        public bool $hasValue,
        public string $formattedValue,
        public bool $showsTrend,
        ?array $series,
    ) {
        $this->sparkline = $showsTrend && null !== $series ? [$series] : [];
        $this->hasSparkline = [] !== $this->sparkline;
    }
}
