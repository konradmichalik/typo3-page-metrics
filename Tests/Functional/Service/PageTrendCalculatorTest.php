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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Service;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricGroup, MetricPolarity, MetricUnit, TrendDirection, TrendSentiment};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, MetricSignificance, RateMetricDefinition};
use KonradMichalik\Typo3PageMetrics\Persistence\DailyMetricsRepository;
use KonradMichalik\Typo3PageMetrics\Service\PageTrendCalculator;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * PageTrendCalculatorTest.
 *
 * Exercises the ranking edge cases the widget tests do not reach: pages whose
 * value is zero on both sides of the comparison, an unchanged value, and a
 * rate whose denominator is zero.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageTrendCalculatorTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private PageTrendCalculator $subject;
    private DailyMetricsRepository $dailyMetrics;
    private DateRange $current;
    private DateRange $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->subject = $this->get(PageTrendCalculator::class);
        $this->dailyMetrics = $this->get(DailyMetricsRepository::class);
        $this->current = new DateRange(new DateTimeImmutable('2026-08-08'), new DateTimeImmutable('2026-08-14'));
        $this->previous = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-07'));
    }

    public function testSkipsPagesWhoseValueIsZeroInBothPeriods(): void
    {
        $this->upsert(2, 'matomo.pageviews', '2026-08-10', 0);
        $this->upsert(2, 'matomo.pageviews', '2026-08-03', 0);
        $this->upsert(3, 'matomo.pageviews', '2026-08-10', 5);

        $ranked = $this->subject->rankedByValue([2, 3], 0, $this->countMetric(), $this->current, $this->previous, 10);

        self::assertSame([3], array_map(static fn ($page): int => $page->pageUid, $ranked));
    }

    public function testReportsAnUnchangedValueAsAFlatTrendWithZeroDelta(): void
    {
        $this->upsert(2, 'matomo.pageviews', '2026-08-10', 10);
        $this->upsert(2, 'matomo.pageviews', '2026-08-03', 10);

        $ranked = $this->subject->rankedByValue([2], 0, $this->countMetric(), $this->current, $this->previous, 10);

        self::assertCount(1, $ranked);
        self::assertStringContainsString('10', $ranked[0]->formattedValue);
        self::assertSame(0, $ranked[0]->deltaPercent);
        self::assertSame(TrendDirection::Flat, $ranked[0]->trend);
    }

    public function testSkipsPagesWhoseRateDenominatorIsZeroInBothPeriods(): void
    {
        $this->upsert(2, 'matomo.entries', '2026-08-10', 0);
        $this->upsert(2, 'matomo.entries', '2026-08-03', 0);
        $this->upsert(3, 'matomo.entries', '2026-08-10', 4);
        $this->upsert(3, 'matomo.entry_bounces', '2026-08-10', 1);

        $ranked = $this->subject->rankedByValue([2, 3], 0, $this->rateMetric(), $this->current, $this->previous, 10);

        self::assertSame([3], array_map(static fn ($page): int => $page->pageUid, $ranked));
    }

    public function testTreatsARateWithAZeroDenominatorInOnePeriodAsZero(): void
    {
        // Denominator only in the previous period, so the page survives the
        // "zero on both sides" filter but its current ratio divides by zero.
        $this->upsert(2, 'matomo.entries', '2026-08-03', 4);
        $this->upsert(2, 'matomo.entry_bounces', '2026-08-03', 2);
        $this->upsert(2, 'matomo.entries', '2026-08-10', 0);

        $ranked = $this->subject->rankedByValue([2], 0, $this->rateMetric(), $this->current, $this->previous, 10);

        self::assertCount(1, $ranked);
        self::assertStringContainsString('0', $ranked[0]->formattedValue);
        self::assertSame(TrendDirection::Down, $ranked[0]->trend);
    }

    public function testRanksARisingLowerIsBetterMetricAsADecline(): void
    {
        // Page 2's bounce rate rises from 25% to 75%, page 3's falls from
        // 75% to 25%. For a LowerIsBetter metric only page 2 declined.
        $this->upsert(2, 'matomo.entries', '2026-08-03', 4);
        $this->upsert(2, 'matomo.entry_bounces', '2026-08-03', 1);
        $this->upsert(2, 'matomo.entries', '2026-08-10', 4);
        $this->upsert(2, 'matomo.entry_bounces', '2026-08-10', 3);
        $this->upsert(3, 'matomo.entries', '2026-08-03', 4);
        $this->upsert(3, 'matomo.entry_bounces', '2026-08-03', 3);
        $this->upsert(3, 'matomo.entries', '2026-08-10', 4);
        $this->upsert(3, 'matomo.entry_bounces', '2026-08-10', 1);

        $declined = $this->subject->rankedByTrend([2, 3], 0, $this->rateMetric(), $this->current, $this->previous, TrendSentiment::Negative, 10);
        $improved = $this->subject->rankedByTrend([2, 3], 0, $this->rateMetric(), $this->current, $this->previous, TrendSentiment::Positive, 10);

        self::assertSame([2], array_map(static fn ($page): int => $page->pageUid, $declined));
        self::assertSame(TrendDirection::Up, $declined[0]->trend);
        self::assertSame(TrendSentiment::Negative, $declined[0]->sentiment);
        self::assertSame([3], array_map(static fn ($page): int => $page->pageUid, $improved));
    }

    public function testRanksTheBiggestDeclineOfALowerIsBetterMetricFirst(): void
    {
        // Page 2 worsens by 200%, page 3 by 100%. Sorting on the raw
        // percentage ascending would put the smaller rise first.
        $this->upsert(2, 'matomo.entries', '2026-08-03', 100);
        $this->upsert(2, 'matomo.entry_bounces', '2026-08-03', 10);
        $this->upsert(2, 'matomo.entries', '2026-08-10', 100);
        $this->upsert(2, 'matomo.entry_bounces', '2026-08-10', 30);
        $this->upsert(3, 'matomo.entries', '2026-08-03', 100);
        $this->upsert(3, 'matomo.entry_bounces', '2026-08-03', 10);
        $this->upsert(3, 'matomo.entries', '2026-08-10', 100);
        $this->upsert(3, 'matomo.entry_bounces', '2026-08-10', 20);

        $declined = $this->subject->rankedByTrend([2, 3], 0, $this->rateMetric(), $this->current, $this->previous, TrendSentiment::Negative, 10);

        self::assertSame([2, 3], array_map(static fn ($page): int => $page->pageUid, $declined));
    }

    public function testFallsBackToDirectionForAMetricWithoutPolarity(): void
    {
        // An avg. time on page is MetricPolarity::Neutral, so every page
        // would be TrendSentiment::Neutral and the widget permanently empty.
        $this->upsert(2, 'matomo.visits', '2026-08-03', 10);
        $this->upsert(2, 'matomo.time_spent_seconds', '2026-08-03', 100);
        $this->upsert(2, 'matomo.visits', '2026-08-10', 10);
        $this->upsert(2, 'matomo.time_spent_seconds', '2026-08-10', 300);

        $risen = $this->subject->rankedByTrend([2], 0, $this->neutralRateMetric(), $this->current, $this->previous, TrendSentiment::Positive, 10);

        self::assertSame([2], array_map(static fn ($page): int => $page->pageUid, $risen));
        self::assertSame(TrendSentiment::Neutral, $risen[0]->sentiment);
    }

    public function testAMovementTooSmallToMeanAnythingIsLeftOut(): void
    {
        // The issue this exists for: two views becoming four is +100% and
        // used to lead the list of what rose.
        $this->upsert(2, 'matomo.pageviews', '2026-08-03', 2);
        $this->upsert(2, 'matomo.pageviews', '2026-08-10', 4);

        $risen = $this->subject->rankedByTrend([2], 0, $this->flooredCountMetric(), $this->current, $this->previous, TrendSentiment::Positive, 10);

        self::assertSame([], $risen);
    }

    public function testALargeMovementOutranksALargePercentage(): void
    {
        // Page 2 loses a quarter of four thousand views, page 3 loses eleven
        // of twenty. Page 3 fell by far the larger share, and page 2 is the
        // one somebody has to look at.
        $this->upsert(2, 'matomo.pageviews', '2026-08-03', 4000);
        $this->upsert(2, 'matomo.pageviews', '2026-08-10', 3000);
        $this->upsert(3, 'matomo.pageviews', '2026-08-03', 20);
        $this->upsert(3, 'matomo.pageviews', '2026-08-10', 9);

        $fallen = $this->subject->rankedByTrend([2, 3], 0, $this->flooredCountMetric(), $this->current, $this->previous, TrendSentiment::Negative, 10);

        self::assertSame([2, 3], array_map(static fn ($page): int => $page->pageUid, $fallen));
    }

    public function testAMetricWithoutItsOwnThresholdStillHasAFloor(): void
    {
        // Nothing obliges a provider to name one, and "no threshold at all"
        // is what put the noise at the top in the first place.
        $this->upsert(2, 'matomo.pageviews', '2026-08-03', 100);
        $this->upsert(2, 'matomo.pageviews', '2026-08-10', 104);

        $risen = $this->subject->rankedByTrend([2], 0, $this->countMetric(), $this->current, $this->previous, TrendSentiment::Positive, 10);

        self::assertSame([], $risen);
    }

    public function testAnAbsoluteFloorDoesNotSwallowARealMovementOnASmallPage(): void
    {
        // Both thresholds have to be cleared, but clearing both is enough:
        // a page that went from twenty to sixty is small and still news.
        $this->upsert(2, 'matomo.pageviews', '2026-08-03', 20);
        $this->upsert(2, 'matomo.pageviews', '2026-08-10', 60);

        $risen = $this->subject->rankedByTrend([2], 0, $this->flooredCountMetric(), $this->current, $this->previous, TrendSentiment::Positive, 10);

        self::assertSame([2], array_map(static fn ($page): int => $page->pageUid, $risen));
    }

    public function testARateNeedsPercentagePointsRatherThanPercent(): void
    {
        // 30% to 33% is a tenth in relative terms and three points in the
        // metric's own unit, which is what the floor is expressed in.
        $this->upsert(2, 'matomo.entries', '2026-08-03', 1000);
        $this->upsert(2, 'matomo.entry_bounces', '2026-08-03', 300);
        $this->upsert(2, 'matomo.entries', '2026-08-10', 1000);
        $this->upsert(2, 'matomo.entry_bounces', '2026-08-10', 320);

        $declined = $this->subject->rankedByTrend([2], 0, $this->flooredRateMetric(), $this->current, $this->previous, TrendSentiment::Negative, 10);

        self::assertSame([], $declined);
    }

    public function testARateSwingOnAHandfulOfVisitsRanksBelowASmallerOneOnMany(): void
    {
        // Page 2 swings forty points on five entries, page 3 swings ten on
        // two thousand. The second is the one that happened to anybody.
        $this->upsert(2, 'matomo.entries', '2026-08-03', 5);
        $this->upsert(2, 'matomo.entry_bounces', '2026-08-03', 1);
        $this->upsert(2, 'matomo.entries', '2026-08-10', 5);
        $this->upsert(2, 'matomo.entry_bounces', '2026-08-10', 3);
        $this->upsert(3, 'matomo.entries', '2026-08-03', 2000);
        $this->upsert(3, 'matomo.entry_bounces', '2026-08-03', 400);
        $this->upsert(3, 'matomo.entries', '2026-08-10', 2000);
        $this->upsert(3, 'matomo.entry_bounces', '2026-08-10', 600);

        $declined = $this->subject->rankedByTrend([2, 3], 0, $this->flooredRateMetric(), $this->current, $this->previous, TrendSentiment::Negative, 10);

        self::assertSame([3, 2], array_map(static fn ($page): int => $page->pageUid, $declined));
    }

    public function testRanksNothingByTrendWhileThePreviousPeriodIsNotFullyImported(): void
    {
        // A previous period the import only partly reached sums to less than
        // it was, and every page rises by exactly that difference. A page
        // with no previous row at all is already dropped by the significance
        // threshold, which is why the partial window is the case that put
        // fabricated risers at the top of the widget.
        $this->upsert(2, 'matomo.pageviews', '2026-08-03', 100);
        $this->upsert(2, 'matomo.pageviews', '2026-08-10', 400);

        $risen = $this->subject->rankedByTrend([2], 0, $this->flooredCountMetric(), $this->current, $this->previous, TrendSentiment::Positive, 10, false);

        self::assertSame([], $risen);
    }

    public function testRanksTheSameMovementOnceTheWholePreviousPeriodIsImported(): void
    {
        $this->upsert(2, 'matomo.pageviews', '2026-08-03', 100);
        $this->upsert(2, 'matomo.pageviews', '2026-08-10', 400);

        $risen = $this->subject->rankedByTrend([2], 0, $this->flooredCountMetric(), $this->current, $this->previous, TrendSentiment::Positive, 10);

        self::assertSame([2], array_map(static fn ($page): int => $page->pageUid, $risen));
    }

    public function testStillRanksByValueWithoutAComparisonWhileThePreviousPeriodWasNeverImported(): void
    {
        // The current period's numbers are real, so the ranking stands. Only
        // the comparison beside each row is the thing nobody can make.
        $this->upsert(2, 'matomo.pageviews', '2026-08-10', 5);
        $this->upsert(3, 'matomo.pageviews', '2026-08-10', 20);

        $ranked = $this->subject->rankedByValue([2, 3], 0, $this->countMetric(), $this->current, $this->previous, 10, false);

        self::assertSame([3, 2], array_map(static fn ($page): int => $page->pageUid, $ranked));
        self::assertTrue($ranked[0]->hasNoComparison);
        self::assertNull($ranked[0]->deltaPercent);
    }

    public function testAGroupedRankingWithholdsTheSameComparison(): void
    {
        $this->upsert(2, 'matomo.pageviews', '2026-08-10', 5);
        $this->upsert(3, 'matomo.pageviews', '2026-08-10', 20);

        $ranked = $this->subject->rankedByValueGrouped([2 => [2, 3]], 0, $this->countMetric(), $this->current, $this->previous, 10, false);

        self::assertCount(1, $ranked);
        self::assertSame('25', $ranked[0]->formattedValue);
        self::assertTrue($ranked[0]->hasNoComparison);
    }

    public function testAnImportedPreviousPeriodStillProducesAComparison(): void
    {
        $this->upsert(2, 'matomo.pageviews', '2026-08-03', 10);
        $this->upsert(2, 'matomo.pageviews', '2026-08-10', 20);

        $ranked = $this->subject->rankedByValue([2], 0, $this->countMetric(), $this->current, $this->previous, 10);

        self::assertFalse($ranked[0]->hasNoComparison);
        self::assertSame(100, $ranked[0]->deltaPercent);
    }

    private function flooredCountMetric(): MetricDefinition
    {
        return new MetricDefinition('matomo.pageviews', 'Page views', 'Views', 'actions-view', MetricUnit::Count, 10, '#0969da', MetricPolarity::HigherIsBetter, MetricGroup::Audience, new MetricSignificance(0.1, 10));
    }

    private function flooredRateMetric(): RateMetricDefinition
    {
        return new RateMetricDefinition('matomo.bounce_rate', 'Bounce rate', 'Bounces', 'actions-door-exit', MetricUnit::Percent, 40, '#cf222e', 'matomo.entry_bounces', 'matomo.entries', MetricPolarity::LowerIsBetter, new MetricSignificance(0.1, 5));
    }

    private function neutralRateMetric(): RateMetricDefinition
    {
        return new RateMetricDefinition('matomo.avg_time_on_page', 'Avg. time on page', 'Seconds', 'actions-clock', MetricUnit::Seconds, 30, '#bf8700', 'matomo.time_spent_seconds', 'matomo.visits', MetricPolarity::Neutral);
    }

    private function upsert(int $pageUid, string $metric, string $date, int $value): void
    {
        $this->dailyMetrics->upsert($pageUid, 0, 'matomo', new DateTimeImmutable($date), $metric, $value);
    }

    private function countMetric(): MetricDefinition
    {
        return new MetricDefinition('matomo.pageviews', 'Page views', 'Views', 'actions-view', MetricUnit::Count, 10, '#0969da');
    }

    private function rateMetric(): RateMetricDefinition
    {
        return new RateMetricDefinition('matomo.bounce_rate', 'Bounce rate', 'Bounces', 'actions-door-exit', MetricUnit::Percent, 40, '#cf222e', 'matomo.entry_bounces', 'matomo.entries', MetricPolarity::LowerIsBetter);
    }
}
