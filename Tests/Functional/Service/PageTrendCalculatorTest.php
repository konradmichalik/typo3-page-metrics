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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricPolarity, MetricUnit, TrendDirection, TrendSentiment};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, RateMetricDefinition};
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
