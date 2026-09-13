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

namespace KonradMichalik\Typo3PageMetrics\Service;

use Generator;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{CsvExport, DateRange, MetricTile, MetricTotal, PageMetricsViewData, PeriodComparison, SitePagesColumn, SitePagesExportRow};

use function array_map;
use function sprintf;

/**
 * CsvExportBuilder.
 *
 * What a view of the backend module looks like as a file. One export per
 * view, carrying the rows that view is showing, in its order, over its
 * period, and nothing it is not showing.
 *
 * Two rules shape every column here.
 *
 * The first is that a value is exported in the unit the fact table stores
 * it in: a duration is 183 and not "3:03", money is minor units. The page
 * view's tiles carry that number themselves (MetricTile::$value and
 * $previousValue), so the page export is a straight read of the view's own
 * object. The compare view's rows do not, so its raw figures are read again
 * and handed in as MetricTotal, keyed by metric identifier: the builder
 * keeps sole ownership of which rows exist, in what order, and whether the
 * two periods may be held against each other at all, and this class only
 * fills in the numbers under that verdict. Where the builder says a row is
 * not comparable, the reference column stays empty even though a figure for
 * it was read, because the view says the same and a file that disagrees
 * with the screen it came from is worse than a file with a gap.
 *
 * The second is that no column name and no cell is ever in the interface
 * language. Column names are fixed English tokens and a metric is named by
 * its identifier, the thing that is stable across a release and across a
 * backend language; the label beside it in the module is a reading aid and
 * would make two exports of the same view incomparable the moment somebody
 * switched languages.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class CsvExportBuilder
{
    /**
     * Why a row has no comparison figures, as a token rather than as a
     * sentence: `new` is a claim about the page (it was measured before and
     * held nothing), `unavailable` is a claim about the import (those days
     * were never fetched). The module draws that distinction in two
     * different badges and a file that folded them into one empty cell
     * would lose the only part of it that is actionable.
     */
    private const COMPARISON_OK = 'ok';
    private const COMPARISON_NEW = 'new';
    private const COMPARISON_UNAVAILABLE = 'unavailable';

    public function forPageView(PageMetricsViewData $viewData, DateRange $range): CsvExport
    {
        return CsvExport::named(
            'page',
            sprintf('page-%d', $viewData->pageId),
            $range,
            ['metric', 'unit', 'value', 'previous_value', 'delta_percent', 'comparison'],
            self::pageRows($viewData),
        );
    }

    /**
     * $currentTotals and $referenceTotals are keyed by metric identifier and
     * come from SiteMetricValueReader over the very ranges this comparison
     * was built for. A metric missing from them keeps its row: the view
     * shows one, and dropping it here would make the file quietly shorter
     * than the screen.
     *
     * @param array<string, MetricTotal> $currentTotals
     * @param array<string, MetricTotal> $referenceTotals
     */
    public function forComparison(PeriodComparison $comparison, int $pageId, DateRange $range, array $currentTotals, array $referenceTotals): CsvExport
    {
        return CsvExport::named(
            'compare',
            sprintf('page-%d', $pageId),
            $range,
            ['metric', 'unit', 'value', 'reference_value', 'difference', 'delta_percent', 'significant', 'comparison'],
            self::comparisonRows($comparison, $currentTotals, $referenceTotals),
        );
    }

    /**
     * The site-wide table, every row of it rather than the twenty-five on
     * screen: a pager is a property of a screen and not of a file, and the
     * rows arrive from SitePagesTableBuilder::stream() in the order and over
     * the period the view itself would show them in.
     *
     * $trendMetric names the metric the movement column is about, because
     * the table's own header says "Trend: Page views" and a column called
     * only delta_percent in a file with six metric columns says nothing.
     *
     * @param list<SitePagesColumn>        $columns
     * @param iterable<SitePagesExportRow> $rows
     */
    public function forSitePages(string $siteIdentifier, array $columns, string $trendMetric, iterable $rows, DateRange $range): CsvExport
    {
        $header = [
            'page_uid',
            'page_title',
            ...array_map(static fn (SitePagesColumn $column): string => $column->key, $columns),
            'trend_metric',
            'trend_delta_percent',
            'comparison',
            'days_since_change',
            'decaying',
        ];

        return CsvExport::named('pages', $siteIdentifier, $range, $header, self::sitePagesRows($columns, $trendMetric, $rows));
    }

    /**
     * @param list<SitePagesColumn>        $columns
     * @param iterable<SitePagesExportRow> $rows
     *
     * @return Generator<int, list<string|int|null>>
     */
    private static function sitePagesRows(array $columns, string $trendMetric, iterable $rows): Generator
    {
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                // Null where the metric was never recorded for this page and
                // the absence is not a zero, which is the distinction the
                // table draws with an empty cell and a file would otherwise
                // lose to a plausible-looking nought.
                $values[] = $row->values[$column->key] ?? null;
            }

            yield [
                $row->pageUid,
                $row->title,
                ...$values,
                $trendMetric,
                $row->deltaPercent,
                self::comparisonState($row->hasComparison, $row->deltaPercent),
                $row->daysSinceChange,
                $row->isDecaying ? 1 : 0,
            ];
        }
    }

    /**
     * @return Generator<int, list<string|int|null>>
     */
    private static function pageRows(PageMetricsViewData $viewData): Generator
    {
        foreach ($viewData->tiles as $tile) {
            yield [
                $tile->identifier,
                $tile->unit->value,
                $tile->value,
                // Zero is a measured nothing and null is an unmeasured
                // period; the two must not both print as an empty cell.
                //
                // Empty as well for a tile that claims a movement its raw
                // previous value cannot account for. $previousValue is the
                // last parameter of an `@api` constructor and defaults to
                // zero, so a tile contributed through ModifyMetricTilesEvent
                // carries no previous figure at all while still carrying a
                // delta percent, and writing that zero would produce a row
                // that contradicts itself in a file the docs describe as
                // sum-able. A listener that wants the column fills the
                // parameter in.
                self::previousValueOf($tile),
                $tile->deltaPercent,
                self::comparisonState(!$tile->hasNoComparison, $tile->deltaPercent),
            ];
        }
    }

    /**
     * Null rather than a figure wherever the tile cannot stand behind one:
     * the period was never imported, or the tile reports a change while its
     * raw previous value is the constructor's default. The formatted string
     * beside it is not consulted, because a display string used as a datum is
     * the thing this whole export exists to avoid.
     */
    private static function previousValueOf(MetricTile $tile): ?int
    {
        if ($tile->hasNoComparison) {
            return null;
        }

        $claimsAMovement = null !== $tile->deltaPercent && 0 !== $tile->deltaPercent;

        return $claimsAMovement && 0 === $tile->previousValue ? null : $tile->previousValue;
    }

    /**
     * @param array<string, MetricTotal> $currentTotals
     * @param array<string, MetricTotal> $referenceTotals
     *
     * @return Generator<int, list<string|int|null>>
     */
    private static function comparisonRows(PeriodComparison $comparison, array $currentTotals, array $referenceTotals): Generator
    {
        foreach ($comparison->rows as $row) {
            $current = $currentTotals[$row->identifier] ?? null;
            $reference = $row->hasNoComparison ? null : ($referenceTotals[$row->identifier] ?? null);

            yield [
                $row->identifier,
                $current?->unit->value ?? '',
                $current?->value,
                $reference?->value,
                // Subtracted here rather than taken from the row's own
                // formatted difference, which carries a sign the formatter
                // cannot spell for a duration or for money.
                null !== $current && null !== $reference ? $current->value - $reference->value : null,
                $row->deltaPercent,
                $row->isSignificant ? 1 : 0,
                self::comparisonState(!$row->hasNoComparison, $row->deltaPercent),
            ];
        }
    }

    private static function comparisonState(bool $hasComparison, ?int $deltaPercent): string
    {
        return match (true) {
            !$hasComparison => self::COMPARISON_UNAVAILABLE,
            null === $deltaPercent => self::COMPARISON_NEW,
            default => self::COMPARISON_OK,
        };
    }
}
