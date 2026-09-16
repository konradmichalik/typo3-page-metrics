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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{BenchmarkBand, MetricPolarity, MetricUnit, TrendDirection};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{MetricTile, TileBenchmark, TileStat};
use KonradMichalik\Typo3PageMetrics\Service\TileStatsCalculator;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function sprintf;

/**
 * TileStatsCalculatorTest.
 *
 * sparkline.js derives the same four figures from the same series for its
 * popover, in the browser, from a data attribute. Nothing can be shared
 * between that and this, so what keeps the two from drifting apart is that
 * the rules are pinned here: a change made on one side and not the other
 * fails rather than quietly disagreeing with the tile beside it.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TileStatsCalculatorTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private TileStatsCalculator $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Controller/Ajax/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $this->subject = $this->get(TileStatsCalculator::class);
    }

    public function testReadsTheLastTwoDaysAsPositionsRatherThanAsTheLastTwoWithData(): void
    {
        // The import marks a day complete only once it has elapsed, so the
        // final point is routinely still empty. Filtering first would
        // silently relabel whichever days do carry numbers.
        $stats = $this->subject->forTile($this->tile(['2026-09-10' => 5, '2026-09-11' => 40, '2026-09-12' => null]));

        self::assertNull($this->valueOf($stats, 'Last day'));
        self::assertSame('40', $this->valueOf($stats, 'Previous day'));
    }

    public function testReadsThePeakAndTheAverageOnlyFromTheDaysThatHaveData(): void
    {
        // Counting an uncovered day as zero drags the average down and
        // reports a peak of 0 for a period nobody imported.
        $stats = $this->subject->forTile($this->tile(['2026-09-10' => 10, '2026-09-11' => null, '2026-09-12' => 20]));

        self::assertSame('20', $this->valueOf($stats, 'Peak'));
        self::assertSame('15', $this->valueOf($stats, 'Average'));
    }

    public function testRoundsTheAverageRatherThanTruncatingIt(): void
    {
        $stats = $this->subject->forTile($this->tile(['2026-09-10' => 10, '2026-09-11' => 11]));

        self::assertSame('11', $this->valueOf($stats, 'Average'));
    }

    public function testReportsNoDataRatherThanAZeroForAPeriodNobodyImported(): void
    {
        // A formatted zero is a measurement. The absence of one is not.
        $stats = $this->subject->forTile($this->tile(['2026-09-10' => null, '2026-09-11' => null]));

        self::assertNull($this->valueOf($stats, 'Peak'));
        self::assertNull($this->valueOf($stats, 'Average'));
        self::assertNull($this->valueOf($stats, 'Last day'));
    }

    public function testFormatsEveryFigureInTheMetricsOwnUnit(): void
    {
        // "0:03" is a time on page. "3" is a number nobody can place.
        $stats = $this->subject->forTile($this->tile(['2026-09-10' => 125], MetricUnit::Seconds));

        self::assertSame('2:05', $this->valueOf($stats, 'Peak'));
    }

    public function testLeavesOutTheFiguresThatWouldStandForNothing(): void
    {
        // No redirects, no record traffic and too few pages to compare
        // against: three figures that are absent rather than zero.
        $stats = $this->subject->forTile($this->tile(['2026-09-10' => 10]));

        self::assertSame(['Last day', 'Previous day', 'Peak', 'Average'], array_map(static fn (TileStat $stat): string => $stat->label, $stats));
    }

    public function testAddsTheRedirectShareTheRecordFigureAndTheBenchmarkWhereTheyExist(): void
    {
        $stats = $this->subject->forTile($this->tile(
            ['2026-09-10' => 10],
            MetricUnit::Count,
            12,
            '830',
            new TileBenchmark('900', BenchmarkBand::AboveMedian, 24),
        ));

        self::assertSame('12%', $this->valueOf($stats, 'Via redirects'));
        self::assertSame('830', $this->valueOf($stats, 'On records below'));
        self::assertSame('900', $this->valueOf($stats, 'Typical page'));
    }

    public function testSaysWhereThePageSitsAgainstTheTypicalOne(): void
    {
        // The median alone is only a finding once you know which side of it
        // this page is on.
        $stats = $this->subject->forTile($this->tile(
            ['2026-09-10' => 10],
            MetricUnit::Count,
            0,
            '',
            new TileBenchmark('900', BenchmarkBand::AboveMedian, 24),
        ));

        $hint = $this->statOf($stats, 'Typical page')->hint;

        self::assertStringContainsString('24', $hint);
        self::assertStringContainsString('This page is above it', $hint);
    }

    /**
     * @param array<string, int|null> $sparkline
     */
    private function tile(
        array $sparkline,
        MetricUnit $unit = MetricUnit::Count,
        int $redirectSharePercent = 0,
        string $recordFormattedValue = '',
        ?TileBenchmark $benchmark = null,
    ): MetricTile {
        return new MetricTile(
            'matomo.pageviews',
            'Page views',
            'How often this page was viewed.',
            'tabler-eye-search',
            '#0969da',
            $unit,
            10,
            '10',
            $sparkline,
            0,
            TrendDirection::Flat,
            $redirectSharePercent,
            null,
            '10',
            MetricPolarity::HigherIsBetter,
            [],
            $benchmark,
            true,
            10,
            $recordFormattedValue,
        );
    }

    /**
     * @param list<TileStat> $stats
     */
    private function valueOf(array $stats, string $label): ?string
    {
        return $this->statOf($stats, $label)->value;
    }

    /**
     * @param list<TileStat> $stats
     */
    private function statOf(array $stats, string $label): TileStat
    {
        foreach ($stats as $stat) {
            if ($stat->label === $label) {
                return $stat;
            }
        }

        self::fail(sprintf('No stat labelled "%s" among %s.', $label, implode(', ', array_map(static fn (TileStat $stat): string => $stat->label, $stats))));
    }
}
