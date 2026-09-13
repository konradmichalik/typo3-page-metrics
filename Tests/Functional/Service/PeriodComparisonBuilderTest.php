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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\TrendDirection;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{ComparisonBaseline, DateRange, PeriodComparison, PeriodComparisonRow};
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, ImportStateRepository};
use KonradMichalik\Typo3PageMetrics\Service\PeriodComparisonBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function array_map;
use function sprintf;

/**
 * PeriodComparisonBuilderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PeriodComparisonBuilderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    public function testNoPageMeansNothingToCompare(): void
    {
        $this->writeSiteConfiguration();

        self::assertNull($this->subject()->build(0, 0, 7, 'previous'));
    }

    public function testBothPeriodsAreReportedWithTheirDifference(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 130);
        $repository->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.pageviews', 100);

        $row = $this->rowFor($this->build(), 'matomo.pageviews');

        self::assertSame('130', $row->formattedCurrentValue);
        self::assertSame('100', $row->formattedReferenceValue);
        self::assertSame('+30', $row->formattedDifference);
        self::assertSame(30, $row->deltaPercent);
        self::assertSame(TrendDirection::Up, $row->direction);
        self::assertFalse($row->hasNoComparison);
    }

    public function testADropIsSignedRatherThanFormattedAsANegativeNumber(): void
    {
        // The formatter spells a duration as minutes and seconds, and a
        // negative one through it reads "-1:-10".
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.visits', 10);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.time_spent_seconds', 600);
        $repository->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.visits', 10);
        $repository->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.time_spent_seconds', 1300);

        $row = $this->rowFor($this->build(), 'matomo.avg_time_on_page');

        self::assertSame('1:00', $row->formattedCurrentValue);
        self::assertSame('2:10', $row->formattedReferenceValue);
        self::assertSame('-1:10', $row->formattedDifference);
    }

    public function testAMovementUnderTheProvidersOwnFloorIsNotSignificant(): void
    {
        // Five views on a hundred clears neither the tenth nor the ten-view
        // floor the Matomo provider names, and a table that badges it looks
        // exactly like one that badges a real move.
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 105);
        $repository->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.pageviews', 100);

        $row = $this->rowFor($this->build(), 'matomo.pageviews');

        self::assertSame(5, $row->deltaPercent);
        self::assertFalse($row->isSignificant);
    }

    public function testAMovementClearingBothThresholdsIsSignificant(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 130);
        $repository->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.pageviews', 100);

        self::assertTrue($this->rowFor($this->build(), 'matomo.pageviews')->isSignificant);
    }

    public function testAMetricWithoutItsOwnThresholdFallsBackToTheSharedDefault(): void
    {
        // Avg. time on page names no significance, so the tenth every other
        // surface applies is what decides it here too: ten seconds on a
        // hundred is under it, and nothing about the metric is special.
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.visits', 1);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.time_spent_seconds', 105);
        $repository->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.visits', 1);
        $repository->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.time_spent_seconds', 100);

        self::assertFalse($this->rowFor($this->build(), 'matomo.avg_time_on_page')->isSignificant);
    }

    public function testAReferencePeriodOutsideTheImportedWindowIsNotComparedAgainst(): void
    {
        // The sum over days nobody fetched is made of the same fabricated
        // zeros the sparkline leaves blank, and a page that has run for
        // years would read as having doubled.
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today, new DateRange($today->modify('-6 days'), $today), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 130);

        $row = $this->rowFor($this->build(), 'matomo.pageviews');

        self::assertTrue($row->hasNoComparison);
        self::assertFalse($row->isNew);
        self::assertFalse($row->isSignificant);
        self::assertNull($row->deltaPercent);
        self::assertSame('', $row->formattedReferenceValue);
        self::assertSame('', $row->formattedDifference);
        // The period the reader chose still has numbers of its own.
        self::assertSame('130', $row->formattedCurrentValue);
    }

    public function testAPeriodThatNeverRecordedThisMetricIsNewRatherThanUncomparable(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 130);

        $row = $this->rowFor($this->build(), 'matomo.pageviews');

        self::assertTrue($row->isNew);
        self::assertFalse($row->hasNoComparison);
        self::assertNull($row->deltaPercent);
    }

    public function testTheYearBaselineReadsTheSameDaysTwelveMonthsEarlier(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $reference = ComparisonBaseline::fromKey('year')->reference(new DateRange($today->modify('-6 days'), $today), 7);
        $repository = $this->get(DailyMetricsRepository::class);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 130);
        $repository->upsert(2, 0, 'matomo', $reference->from->modify('+2 days'), 'matomo.pageviews', 100);

        $comparison = $this->build('year');

        self::assertSame('year', $comparison->baseline->key);
        self::assertSame(
            sprintf('%s to %s', $reference->from->format('Y-m-d'), $reference->until->format('Y-m-d')),
            $comparison->referenceRangeLabel,
        );
        self::assertSame('100', $this->rowFor($comparison, 'matomo.pageviews')->formattedReferenceValue);
    }

    public function testTheDefaultBaselineIsTheOneEverySurfaceAlreadyShows(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');

        $comparison = $this->build('nonsense');

        self::assertSame('previous', $comparison->baseline->key);
        // Nothing stored yet is the state the view opens in for every reader
        // who has never switched the baseline.
        $unset = $this->subject()->build(2, 0, 7, null);
        self::assertNotNull($unset);
        self::assertSame('previous', $unset->baseline->key);
        self::assertSame(
            sprintf('%s to %s', $today->modify('-13 days')->format('Y-m-d'), $today->modify('-7 days')->format('Y-m-d')),
            $comparison->referenceRangeLabel,
        );
        self::assertSame(
            sprintf('%s to %s', $today->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')),
            $comparison->currentRangeLabel,
        );
    }

    public function testEveryMetricOfTheSiteGetsARowInTheProvidersOwnOrder(): void
    {
        $this->writeSiteConfiguration();

        $comparison = $this->build();

        self::assertSame(
            ['matomo.pageviews', 'matomo.visits', 'matomo.avg_time_on_page', 'matomo.bounce_rate'],
            array_map(static fn (PeriodComparisonRow $row): string => $row->identifier, $comparison->rows),
        );
    }

    public function testTheOptionsTravelWithTheComparisonSoTheViewCanOfferThem(): void
    {
        $this->writeSiteConfiguration();

        self::assertSame(
            ['previous', 'month', 'quarter', 'year'],
            array_map(static fn (ComparisonBaseline $baseline): string => $baseline->key, $this->build()->baselineOptions),
        );
    }

    private function build(string $baselineKey = 'previous'): PeriodComparison
    {
        $comparison = $this->subject()->build(2, 0, 7, $baselineKey);

        self::assertNotNull($comparison);

        return $comparison;
    }

    private function subject(): PeriodComparisonBuilder
    {
        return $this->get(PeriodComparisonBuilder::class);
    }

    private function rowFor(PeriodComparison $comparison, string $identifier): PeriodComparisonRow
    {
        foreach ($comparison->rows as $row) {
            if ($row->identifier === $identifier) {
                return $row;
            }
        }

        self::fail(sprintf('No comparison row for metric identifier "%s".', $identifier));
    }

    private function writeSiteConfiguration(): void
    {
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                0 => [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'navigationTitle' => 'English',
                    'flag' => 'us',
                ],
            ],
            'errorHandling' => [],
            'routes' => [],
            'pageMetrics' => ['providers' => ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']]],
        ]);
    }
}
