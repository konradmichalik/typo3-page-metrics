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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Service;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricPolarity, MetricUnit, TrendDirection, TrendSentiment};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{ComparisonBaseline, CsvExport, DateRange, MetricTile, MetricTotal, PageMetricsViewData, PeriodComparison, PeriodComparisonRow};
use KonradMichalik\Typo3PageMetrics\Service\CsvExportBuilder;
use PHPUnit\Framework\TestCase;

use function array_column;
use function iterator_to_array;

/**
 * CsvExportBuilderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class CsvExportBuilderTest extends TestCase
{
    private CsvExportBuilder $subject;

    private DateRange $range;

    protected function setUp(): void
    {
        $this->subject = new CsvExportBuilder();
        $this->range = new DateRange(new DateTimeImmutable('2026-08-15'), new DateTimeImmutable('2026-09-13'));
    }

    public function testNamesThePageExportAfterThePageAndThePeriod(): void
    {
        $export = $this->subject->forPageView($this->viewData(12, [$this->tile('matomo.pageviews')]), $this->range);

        self::assertSame('page-metrics-page-page-12-2026-08-15_2026-09-13.csv', $export->filename);
    }

    public function testTheHeaderNamesColumnsByIdentifierAndNeverInTheInterfaceLanguage(): void
    {
        $export = $this->subject->forPageView($this->viewData(12, []), $this->range);

        self::assertSame(['metric', 'unit', 'value', 'previous_value', 'delta_percent', 'comparison'], $export->header);
    }

    public function testTheRowsAreTheTilesInTheOrderTheViewShowsThem(): void
    {
        $viewData = $this->viewData(12, [
            $this->tile('matomo.pageviews'),
            $this->tile('matomo.avg_time_on_page', MetricUnit::Seconds),
        ]);

        self::assertSame(
            ['matomo.pageviews', 'matomo.avg_time_on_page'],
            array_column($this->rowsOf($this->subject->forPageView($viewData, $this->range)), 0),
        );
    }

    public function testADurationIsExportedAsItsOwnSecondsAndNotAsTheStringTheTileShows(): void
    {
        $tile = $this->tile('matomo.avg_time_on_page', MetricUnit::Seconds, 183, '3:03', 120);
        $rows = $this->rowsOf($this->subject->forPageView($this->viewData(12, [$tile]), $this->range));

        self::assertSame(['matomo.avg_time_on_page', 'seconds', 183, 120, 25, 'ok'], $rows[0]);
    }

    public function testRevenueKeepsTheMinorUnitsTheFactTableStores(): void
    {
        $tile = $this->tile('matomo.goal_revenue', MetricUnit::Currency, 1250, '12.50', 1000);
        $rows = $this->rowsOf($this->subject->forPageView($this->viewData(12, [$tile]), $this->range));

        self::assertSame(1250, $rows[0][2]);
        self::assertSame(1000, $rows[0][3]);
    }

    public function testAPageWithNoEarlierFiguresSaysSoRatherThanClaimingAChangeOfNothing(): void
    {
        $tile = $this->tile('matomo.pageviews', MetricUnit::Count, 40, '40', 0, null);
        $rows = $this->rowsOf($this->subject->forPageView($this->viewData(12, [$tile]), $this->range));

        self::assertSame([0, null, 'new'], [$rows[0][3], $rows[0][4], $rows[0][5]]);
    }

    public function testAPeriodOutsideTheImportedWindowCarriesNoPreviousFigureAtAll(): void
    {
        // Not a zero and not a change: nobody fetched those days, so there
        // is no number to put in the column.
        $tile = $this->tile('matomo.pageviews', MetricUnit::Count, 40, '40', 0, null, false);
        $rows = $this->rowsOf($this->subject->forPageView($this->viewData(12, [$tile]), $this->range));

        self::assertSame([null, null, 'unavailable'], [$rows[0][3], $rows[0][4], $rows[0][5]]);
    }

    public function testTheComparisonExportNamesItsColumnsForTwoPeriods(): void
    {
        $export = $this->subject->forComparison($this->comparison([]), 12, $this->range, [], []);

        self::assertSame(['metric', 'unit', 'value', 'reference_value', 'difference', 'delta_percent', 'significant', 'comparison'], $export->header);
    }

    public function testTheComparisonRowsTakeTheirValuesFromTheTotalsAndTheirOrderFromTheView(): void
    {
        $comparison = $this->comparison([
            $this->comparisonRow('matomo.visits', 25, true),
            $this->comparisonRow('matomo.pageviews', -10, false),
        ]);
        $current = ['matomo.visits' => new MetricTotal(MetricUnit::Count, 50), 'matomo.pageviews' => new MetricTotal(MetricUnit::Count, 90)];
        $reference = ['matomo.visits' => new MetricTotal(MetricUnit::Count, 40), 'matomo.pageviews' => new MetricTotal(MetricUnit::Count, 100)];

        $rows = $this->rowsOf($this->subject->forComparison($comparison, 12, $this->range, $current, $reference));

        self::assertSame(['matomo.visits', 'count', 50, 40, 10, 25, 1, 'ok'], $rows[0]);
        self::assertSame(['matomo.pageviews', 'count', 90, 100, -10, -10, 0, 'ok'], $rows[1]);
    }

    public function testAnIncomparableComparisonRowCarriesItsOwnFigureAndNothingItCannotBeHeldAgainst(): void
    {
        $comparison = $this->comparison([$this->comparisonRow('matomo.visits', null, false, false)]);
        $current = ['matomo.visits' => new MetricTotal(MetricUnit::Count, 50)];
        // Deliberately present, to prove the export follows the builder's
        // verdict rather than whatever a second query happens to return.
        $reference = ['matomo.visits' => new MetricTotal(MetricUnit::Count, 40)];

        $rows = $this->rowsOf($this->subject->forComparison($comparison, 12, $this->range, $current, $reference));

        self::assertSame(['matomo.visits', 'count', 50, null, null, null, 0, 'unavailable'], $rows[0]);
    }

    public function testAMetricTheTotalsDoNotKnowStillGetsItsRowRatherThanBeingDropped(): void
    {
        $comparison = $this->comparison([$this->comparisonRow('matomo.visits', null, false)]);

        $rows = $this->rowsOf($this->subject->forComparison($comparison, 12, $this->range, [], []));

        self::assertSame(['matomo.visits', '', null, null, null, null, 0, 'new'], $rows[0]);
    }

    public function testWithholdsAPreviousValueATileCannotStandBehind(): void
    {
        // A tile contributed through ModifyMetricTilesEvent never fills the
        // trailing $previousValue parameter, so it arrives as the
        // constructor's zero while the tile still reports a movement.
        // Writing that zero would produce a row saying the page went from
        // nothing to a hundred and rose by twenty five percent, in a file the
        // docs describe as sum-able.
        $tile = new MetricTile('demo.custom', 'Custom', '', 'actions-plus', '#000', MetricUnit::Count, 100, '100', [], 25, TrendDirection::Up, 0, null, '80');

        $rows = iterator_to_array((new CsvExportBuilder())->forPageView($this->viewData(2, [$tile]), new DateRange(new DateTimeImmutable('2026-08-15'), new DateTimeImmutable('2026-09-13')))->rows);

        self::assertSame([['demo.custom', 'count', 100, null, 25, 'ok']], $rows);
    }

    /**
     * @return list<list<string|int|null>>
     */
    private function rowsOf(CsvExport $export): array
    {
        return iterator_to_array($export->rows, false);
    }

    /**
     * @param list<MetricTile> $tiles
     */
    private function viewData(int $pageId, array $tiles): PageMetricsViewData
    {
        return new PageMetricsViewData(true, $pageId, $tiles, 0, 30, [7, 30, 90], '', '', [], false, false, null, null);
    }

    private function tile(string $identifier, MetricUnit $unit = MetricUnit::Count, int $value = 0, string $formatted = '0', int $previousValue = 0, ?int $deltaPercent = 25, bool $previousPeriodCovered = true): MetricTile
    {
        return new MetricTile(
            $identifier,
            'Label',
            'Description',
            'icon',
            '#000000',
            $unit,
            $value,
            $formatted,
            [],
            $deltaPercent,
            TrendDirection::Up,
            0,
            null,
            '',
            MetricPolarity::HigherIsBetter,
            [],
            null,
            $previousPeriodCovered,
            $previousValue,
        );
    }

    /**
     * @param list<PeriodComparisonRow> $rows
     */
    private function comparison(array $rows): PeriodComparison
    {
        return new PeriodComparison(ComparisonBaseline::default(), 30, '', '', $rows, ComparisonBaseline::all());
    }

    private function comparisonRow(string $identifier, ?int $deltaPercent, bool $isSignificant, bool $isComparable = true): PeriodComparisonRow
    {
        return new PeriodComparisonRow(
            $identifier,
            'Label',
            'Description',
            'icon',
            '#000000',
            '',
            '',
            '',
            $deltaPercent,
            TrendDirection::Up,
            TrendSentiment::Neutral,
            $isSignificant,
            $isComparable,
        );
    }
}
