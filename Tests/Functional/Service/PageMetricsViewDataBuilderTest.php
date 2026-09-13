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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{AnnotationCategory, BenchmarkBand, TrendDirection, UnresolvedReason};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange, MetricTile, PageMetricsViewData};
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, ImportStateRepository, UnresolvedUrlRepository};
use KonradMichalik\Typo3PageMetrics\Service\PageMetricsViewDataBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function array_column;
use function array_map;
use function sprintf;

/**
 * PageMetricsViewDataBuilderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageMetricsViewDataBuilderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        // The machine-traffic summary is labelled by this class rather than
        // by a provider, so the builder resolves one string of its own.
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    public function testNoBenchmarkWhileOnlyOnePageHasData(): void
    {
        // Nothing to compare against but itself.
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 40);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30), 'matomo.pageviews');

        self::assertNull($tile->benchmark);
    }

    public function testMedianOfAnEvenFieldIsTheMeanOfTheMiddleTwo(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $repository->upsert(3, 0, 'matomo', $today, 'matomo.pageviews', 30);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30), 'matomo.pageviews');

        self::assertNotNull($tile->benchmark);
        self::assertSame('20', $tile->benchmark->formattedMedian);
        // The tooltip names the width of the field, so a median taken over
        // two pages cannot be read as one taken over the whole site.
        self::assertSame(2, $tile->benchmark->pageCount);
    }

    public function testPagesWithoutDataDoNotCountTowardsTheMedian(): void
    {
        // Page 5 has no row at all. Counting it as a zero would drag the
        // typical page down to a number no page actually had.
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $repository->upsert(3, 0, 'matomo', $today, 'matomo.pageviews', 30);
        $repository->upsert(4, 0, 'matomo', $today, 'matomo.pageviews', 50);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30), 'matomo.pageviews');

        self::assertNotNull($tile->benchmark);
        self::assertSame('30', $tile->benchmark->formattedMedian);
    }

    public function testNoBandUntilThereAreEnoughPagesToDivideIntoFifths(): void
    {
        // Four pages: a median, but a fifth of four is not a fifth.
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        foreach ([[1, 10], [2, 20], [3, 30], [4, 40]] as [$pageUid, $value]) {
            $repository->upsert($pageUid, 0, 'matomo', $today, 'matomo.pageviews', $value);
        }

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30), 'matomo.pageviews');

        self::assertNotNull($tile->benchmark);
        self::assertSame('25', $tile->benchmark->formattedMedian);
        self::assertNull($tile->benchmark->band);
    }

    public function testTheBandNamesWhereThePageSitsInTheField(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        foreach ([[1, 50], [2, 10], [3, 30], [4, 40], [5, 20]] as [$pageUid, $value]) {
            $repository->upsert($pageUid, 0, 'matomo', $today, 'matomo.pageviews', $value);
        }

        $builder = $this->get(PageMetricsViewDataBuilder::class);
        $lowest = $this->tileFor($builder->build(2, 0, 30), 'matomo.pageviews');
        $middle = $this->tileFor($builder->build(3, 0, 30), 'matomo.pageviews');

        self::assertNotNull($lowest->benchmark);
        self::assertSame(BenchmarkBand::BottomFifth, $lowest->benchmark->band);
        self::assertNotNull($middle->benchmark);
        self::assertSame(BenchmarkBand::AtMedian, $middle->benchmark->band);
        self::assertSame('30', $middle->benchmark->formattedMedian);
    }

    public function testAFieldWithNoSpreadGetsNoBand(): void
    {
        // Every page on the same number: there is no ranking in it, and any
        // band would report how ties were broken rather than how the page did.
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        foreach ([1, 2, 3, 4, 5] as $pageUid) {
            $repository->upsert($pageUid, 0, 'matomo', $today, 'matomo.pageviews', 25);
        }

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30), 'matomo.pageviews');

        self::assertNotNull($tile->benchmark);
        self::assertSame('25', $tile->benchmark->formattedMedian);
        self::assertNull($tile->benchmark->band);
    }

    public function testARateBenchmarkDividesPerPageRatherThanAcrossTheSite(): void
    {
        // Page 2 bounces 1 of 4, page 3 bounces 300 of 400, so the per-page
        // rates are 25% and 75% and the typical page sits at 50%. Pooling the
        // site's totals instead would give 301 of 404, about 75%, which is
        // the busy page's rate wearing the whole site's name.
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.entries', 4);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.entry_bounces', 1);
        $repository->upsert(3, 0, 'matomo', $today, 'matomo.entries', 400);
        $repository->upsert(3, 0, 'matomo', $today, 'matomo.entry_bounces', 300);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30), 'matomo.bounce_rate');

        self::assertNotNull($tile->benchmark);
        self::assertSame('50%', $tile->benchmark->formattedMedian);
        // Never a band for a rate: "top fifth" would mean best for one metric
        // and worst for another.
        self::assertNull($tile->benchmark->band);
    }

    public function testAPageWithNoEntriesIsLeftOutOfARateBenchmark(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.entries', 4);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.entry_bounces', 1);
        $repository->upsert(3, 0, 'matomo', $today, 'matomo.entries', 4);
        $repository->upsert(3, 0, 'matomo', $today, 'matomo.entry_bounces', 3);
        // Denominator zero: no rate at all, rather than a rate of nought.
        $repository->upsert(5, 0, 'matomo', $today, 'matomo.entries', 0);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30), 'matomo.bounce_rate');

        self::assertNotNull($tile->benchmark);
        self::assertSame('50%', $tile->benchmark->formattedMedian);
    }

    public function testAggregatingASubtreeSuppressesTheBenchmark(): void
    {
        // The tile is then a section total, and the field it would be
        // measured against is made of individual pages.
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        $repository->upsert(3, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $repository->upsert(5, 0, 'matomo', $today, 'matomo.pageviews', 30);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(3, 0, 30, true), 'matomo.pageviews');

        self::assertNull($tile->benchmark);
    }

    public function testReturnsNoPageWhenPageIdIsZero(): void
    {
        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(0, 0, 30);

        self::assertFalse($viewData->hasPage);
        self::assertSame([], $viewData->tiles);
    }

    public function testReturnsNoPageWhenSiteCannotBeResolved(): void
    {
        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);

        self::assertFalse($viewData->hasPage);
    }

    public function testReturnsNoPageForASysfolder(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(4, 0, 30);

        self::assertFalse($viewData->hasPage);
        self::assertSame([], $viewData->tiles);
    }

    public function testSparklineCoversTheSelectedPeriodInsteadOfAFixedWindow(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 1);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7), 'matomo.pageviews');
        self::assertCount(7, $tile->sparkline);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 90), 'matomo.pageviews');
        self::assertCount(90, $tile->sparkline);
    }

    public function testDaysOutsideTheImportedWindowAreNullRatherThanZero(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        // The import has only ever covered the last two days, so the rest of
        // a seven-day period is not "no traffic", it is "no answer".
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today->modify('-1 day'), new DateRange($today->modify('-1 day'), $today), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 4);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7), 'matomo.pageviews');

        self::assertSame(4, $tile->sparkline[$today->format('Y-m-d')]);
        // Inside the window and with no row: a genuine quiet day.
        self::assertSame(0, $tile->sparkline[$today->modify('-1 day')->format('Y-m-d')]);
        // Before the window: unknown.
        self::assertNull($tile->sparkline[$today->modify('-2 days')->format('Y-m-d')]);
        self::assertSame(4, $tile->value);
    }

    public function testARateTileMarksUncoveredDaysTheSameWay(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today->modify('-1 day'), new DateRange($today->modify('-1 day'), $today), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entries', 4);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entry_bounces', 1);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7), 'matomo.bounce_rate');

        self::assertSame(25, $tile->sparkline[$today->format('Y-m-d')]);
        self::assertSame(0, $tile->sparkline[$today->modify('-1 day')->format('Y-m-d')]);
        self::assertNull($tile->sparkline[$today->modify('-2 days')->format('Y-m-d')]);
    }

    public function testSparklineIsLeftUntouchedWhenNothingHasEverBeenImported(): void
    {
        // Without import state the extension does not know what it covers,
        // so it must not claim a day is missing.
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 4);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7), 'matomo.pageviews');

        self::assertNotContains(null, $tile->sparkline);
    }

    public function testNoComparisonAtAllWhenThePreviousPeriodWasNeverImported(): void
    {
        // The import only ever reached back seven days, so the seven days
        // before this tile's own period were never fetched. Summing them
        // gives a zero nobody measured, against which a page that has
        // existed for years compares as new.
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today, new DateRange($today->modify('-6 days'), $today), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 40);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7), 'matomo.pageviews');

        self::assertTrue($tile->hasNoComparison);
        // Not the same fact, and the one the tile used to report instead.
        self::assertFalse($tile->isNew);
        self::assertNull($tile->deltaPercent);
        self::assertSame('', $tile->previousFormattedValue);
    }

    public function testAPartiallyImportedPreviousPeriodRefusesTheComparisonToo(): void
    {
        // The window reaches three days into the previous period, so its sum
        // understates by exactly the amount nobody can quantify.
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today, new DateRange($today->modify('-9 days'), $today), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 40);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-8 days'), 'matomo.pageviews', 10);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7), 'matomo.pageviews');

        self::assertTrue($tile->hasNoComparison);
        self::assertNull($tile->deltaPercent);
    }

    public function testTheComparisonStandsOnceTheWholePreviousPeriodIsImported(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today, new DateRange($today->modify('-13 days'), $today), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 30);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-8 days'), 'matomo.pageviews', 20);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7), 'matomo.pageviews');

        self::assertFalse($tile->hasNoComparison);
        self::assertSame(50, $tile->deltaPercent);
        self::assertSame(TrendDirection::Up, $tile->trend);
        self::assertSame('20', $tile->previousFormattedValue);
    }

    public function testTheComparisonStandsWhenNothingHasEverBeenImported(): void
    {
        // Same claim as the sparkline's: without import state there is no
        // window to measure the previous period against, so nothing is
        // withheld either - data seeded straight into the fact table (demo
        // fixtures, a restore) would otherwise lose every comparison.
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 30);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-8 days'), 'matomo.pageviews', 20);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7), 'matomo.pageviews');

        self::assertFalse($tile->hasNoComparison);
        self::assertSame(50, $tile->deltaPercent);
    }

    public function testARateTileRefusesTheUnimportedComparisonTheSameWay(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today, new DateRange($today->modify('-6 days'), $today), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entries', 4);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entry_bounces', 1);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7), 'matomo.bounce_rate');

        self::assertTrue($tile->hasNoComparison);
        self::assertFalse($tile->isNew);
        self::assertNull($tile->deltaPercent);
        self::assertSame('', $tile->previousFormattedValue);
    }

    public function testTheMachineSummaryRefusesTheUnimportedComparisonTheSameWay(): void
    {
        $this->writeSiteConfigurationWithAccessLog();
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('accesslog', 'main', $today, new DateRange($today->modify('-6 days'), $today), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'accesslog', $today, 'bot.ai_crawler', 7);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7), 'machine.summary.accesslog');

        self::assertTrue($tile->hasNoComparison);
        self::assertNull($tile->deltaPercent);
        self::assertSame('', $tile->previousFormattedValue);
    }

    public function testReportsTheLastDayTheImportHasNumbersFor(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today->modify('-1 day'), new DateRange($today->modify('-30 days'), $today->modify('-1 day')), true);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);

        self::assertNotNull($viewData->dataFreshness);
        self::assertSame($today->modify('-1 day')->format('Y-m-d'), $viewData->dataFreshness->date->format('Y-m-d'));
        self::assertFalse($viewData->dataFreshness->isStale);
    }

    public function testReportsNoFreshnessBeforeAnythingHasBeenImported(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        self::assertNull($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30)->dataFreshness);
    }

    public function testMarksTheDataStaleOnceTheImportIsMoreThanTwoDaysBehind(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today->modify('-4 days'), new DateRange($today->modify('-30 days'), $today->modify('-3 days')), true);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);

        self::assertTrue($viewData->dataFreshness?->isStale);
    }

    public function testTheStalestProviderDecidesTheRowsFreshness(): void
    {
        // The tile row mixes providers, and it is only as current as its
        // oldest number - reporting the newest would vouch for figures that
        // are days behind it.
        $this->writeSiteConfigurationWithTwoProviders();
        $today = new DateTimeImmutable('today');
        $importState = $this->get(ImportStateRepository::class);
        $importState->markImported('matomo', 'main', $today->modify('-1 day'), new DateRange($today->modify('-30 days'), $today), true);
        $importState->markImported('accesslog', 'main', $today->modify('-6 days'), new DateRange($today->modify('-30 days'), $today->modify('-5 days')), true);

        $freshness = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30)->dataFreshness;

        self::assertNotNull($freshness);
        // The resume cursor, not the covered window: it is the last day the
        // import treats as complete, and it is what every run records.
        self::assertSame($today->modify('-6 days')->format('Y-m-d'), $freshness->date->format('Y-m-d'));
        self::assertTrue($freshness->isStale);
    }

    public function testAProviderThatHasNeverImportedDoesNotHideTheOthersFreshness(): void
    {
        // Its tiles are empty, which shows on its own. Letting it veto the
        // date would lose the freshness of the provider that does work.
        $this->writeSiteConfigurationWithTwoProviders();
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today->modify('-1 day'), new DateRange($today->modify('-30 days'), $today), true);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);

        self::assertNotNull($viewData->dataFreshness);
        self::assertSame($today->modify('-1 day')->format('Y-m-d'), $viewData->dataFreshness->date->format('Y-m-d'));
        self::assertFalse($viewData->dataFreshness->isStale);
    }

    public function testExposesTheCurrentAndPreviousPeriodAsDateRangeLabels(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        self::assertSame(
            sprintf('%s to %s', $today->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')),
            $viewData->currentRangeLabel,
        );
        self::assertSame(
            sprintf('%s to %s', $today->modify('-13 days')->format('Y-m-d'), $today->modify('-7 days')->format('Y-m-d')),
            $viewData->previousRangeLabel,
        );
    }

    public function testReturnsTileTotalForConfiguredMetric(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 42);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);

        self::assertTrue($viewData->hasPage);
        self::assertCount(4, $viewData->tiles);
        $pageviewsTile = array_values(array_filter($viewData->tiles, static fn ($tile) => 'matomo.pageviews' === $tile->identifier))[0];
        self::assertSame(42, $pageviewsTile->value);
    }

    public function testComputesUpwardTrendAgainstThePreviousPeriod(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 20);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.pageviews', 10);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        $tile = $this->tileFor($viewData, 'matomo.pageviews');
        self::assertSame(20, $tile->value);
        self::assertSame(100, $tile->deltaPercent);
        self::assertSame(TrendDirection::Up, $tile->trend);
        self::assertSame('10', $tile->previousFormattedValue);
    }

    public function testReportsNewInsteadOfAPercentWhenThePreviousPeriodHadNoData(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 5);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        $tile = $this->tileFor($viewData, 'matomo.pageviews');
        self::assertNull($tile->deltaPercent);
        self::assertSame(TrendDirection::Up, $tile->trend);
        self::assertSame('', $tile->previousFormattedValue);
    }

    public function testReportsFlatTrendWhenBothPeriodsHaveNoData(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        $tile = $this->tileFor($viewData, 'matomo.pageviews');
        self::assertNull($tile->deltaPercent);
        self::assertSame(TrendDirection::Flat, $tile->trend);
    }

    public function testComputesRedirectSharePercentFromTheViaRedirectDimension(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 30, '');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 6, 'via_redirect');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        $tile = $this->tileFor($viewData, 'matomo.pageviews');
        self::assertSame(20, $tile->redirectSharePercent);
    }

    public function testComputesBounceRateAsARatioOfSumsNotAnAverageOfDailyRatios(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        // Day 1: 5/10 = 50% bounce rate on low traffic.
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entry_bounces', 5);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.entries', 10);
        // Day 2: 9/90 = 10% bounce rate on high traffic.
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-1 day'), 'matomo.entry_bounces', 9);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today->modify('-1 day'), 'matomo.entries', 90);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        $tile = $this->tileFor($viewData, 'matomo.bounce_rate');
        // Naive averaging of the two daily ratios would give (50+10)/2 = 30%;
        // the correct sum(bounces)/sum(entries) = 14/100 = 14%.
        self::assertSame(14, $tile->value);
        self::assertSame('14%', $tile->formattedValue);
    }

    public function testComputesAvgTimeOnPageFromSummedTimeSpentAndVisits(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.time_spent_seconds', 300);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.visits', 3);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        $tile = $this->tileFor($viewData, 'matomo.avg_time_on_page');
        self::assertSame(100, $tile->value);
        self::assertSame('1:40', $tile->formattedValue);
    }

    public function testRateTilesHaveNoRedirectShare(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        $tile = $this->tileFor($viewData, 'matomo.bounce_rate');
        self::assertSame(0, $tile->redirectSharePercent);
    }

    public function testBuildsAnExternalLinkFromTheResolvedPageUrl(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 7);

        $tile = $this->tileFor($viewData, 'matomo.pageviews');
        self::assertNotNull($tile->externalLink);
        self::assertStringContainsString('matomo.example.com', $tile->externalLink);
        self::assertStringContainsString(rawurlencode('pageUrl==https://example.com/about'), $tile->externalLink);
    }

    public function testDefaultsToInvalidPeriodFallback(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 999);

        self::assertSame(30, $viewData->periodDays);
    }

    public function testCountsUnresolvedUrlsForTheSiteWithinThirtyDays(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $this->get(UnresolvedUrlRepository::class)->record(
            'matomo',
            new DateTimeImmutable('today'),
            'https://example.com/gone',
            'matomo.pageviews',
            UnresolvedReason::Unknown,
            3,
            100,
        );

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);

        self::assertSame(1, $viewData->unresolvedCount);
    }

    public function testAnnotationsAreEmptyWhenNoneAreStored(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);

        self::assertSame([], $viewData->annotations);
    }

    public function testAnnotationsWithinTheSelectedPeriodAreExposed(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', new Annotation('1', new DateTimeImmutable('today'), 'Release 2.0'));

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);

        self::assertCount(1, $viewData->annotations);
        self::assertSame('Release 2.0', $viewData->annotations[0]->text);
    }

    public function testAnnotationsCombineImportedAndManuallyCreatedOnesInDateOrder(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', new Annotation('1', $today, 'Imported release'));
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_typo3pagemetrics_manual_annotation')->insert(
            'tx_typo3pagemetrics_manual_annotation',
            ['pid' => 0, 'site_identifier' => 'main', 'date' => $today->modify('-1 day')->getTimestamp(), 'text' => 'Manual note'],
        );

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);

        self::assertCount(2, $viewData->annotations);
        self::assertSame('Manual note', $viewData->annotations[0]->text);
        self::assertSame('Imported release', $viewData->annotations[1]->text);
    }

    public function testAPageScopedAnnotationDoesNotShowOnOtherPages(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', new Annotation('about', $today, 'About page rewrite', AnnotationCategory::Content, null, 2));

        self::assertCount(1, $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30)->annotations);
        self::assertSame([], $this->get(PageMetricsViewDataBuilder::class)->build(4, 0, 30)->annotations);
    }

    public function testAnAncestorsAnnotationReachesDownOnlyWhenItCoversItsSubtree(): void
    {
        // Page 5 sits under 3, which sits under the root page 1 - so this
        // also covers that the builder walks the whole chain rather than
        // just the immediate parent.
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', new Annotation('relaunch', $today, 'Relaunch', AnnotationCategory::Redesign, null, 1, true));
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', new Annotation('rootonly', $today, 'Home only', AnnotationCategory::Content, null, 1));

        $annotations = $this->get(PageMetricsViewDataBuilder::class)->build(5, 0, 30)->annotations;

        self::assertSame(['Relaunch'], array_map(static fn (Annotation $a): string => $a->text, $annotations));
    }

    public function testIncludeSubpagesSumsMetricsAcrossTheWholeSubtree(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 32);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(1, 0, 30, true);

        $tile = $this->tileFor($viewData, 'matomo.pageviews');
        self::assertSame(42, $tile->value);
    }

    public function testIncludeSubpagesFalseKeepsSinglePageTotals(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(1, 0, 'matomo', $today, 'matomo.pageviews', 10);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 32);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(1, 0, 30, false);

        $tile = $this->tileFor($viewData, 'matomo.pageviews');
        self::assertSame(10, $tile->value);
    }

    public function testIncludeSubpagesHidesTheExternalLink(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(1, 0, 30, true);

        $tile = $this->tileFor($viewData, 'matomo.pageviews');
        self::assertNull($tile->externalLink);
    }

    public function testIncludeSubpagesFlagIsExposedOnTheViewData(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        self::assertTrue($this->get(PageMetricsViewDataBuilder::class)->build(1, 0, 30, true)->includeSubpages);
        self::assertFalse($this->get(PageMetricsViewDataBuilder::class)->build(1, 0, 30, false)->includeSubpages);
    }

    public function testHasSubpagesIsTrueForAPageWithChildren(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        self::assertTrue($this->get(PageMetricsViewDataBuilder::class)->build(1, 0, 30)->hasSubpages);
    }

    public function testHasSubpagesIsFalseForALeafPage(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        self::assertFalse($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30)->hasSubpages);
    }

    public function testTheFiveBotBucketsBecomeOneTileInsteadOfFive(): void
    {
        $this->writeSiteConfigurationWithAccessLog();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'accesslog', $today, 'accesslog.pageviews', 40);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'accesslog', $today, 'bot.ai_crawler', 7);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'accesslog', $today, 'bot.search', 3);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);
        $identifiers = array_map(static fn (MetricTile $tile): string => $tile->identifier, $viewData->tiles);

        self::assertContains('accesslog.pageviews', $identifiers);
        // Scoped to the provider that declared the buckets, so two
        // providers with machine metrics cannot produce two tiles that are
        // indistinguishable to a reader or to a lookup by identifier.
        self::assertContains('machine.summary.accesslog', $identifiers);
        foreach (['bot.ai_chatbot', 'bot.ai_crawler', 'bot.search', 'bot.social', 'bot.tool'] as $bucket) {
            self::assertNotContains($bucket, $identifiers);
        }
    }

    public function testTheMachineSummaryAddsUpItsBucketsAndKeepsThemAsItsParts(): void
    {
        $this->writeSiteConfigurationWithAccessLog();
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'accesslog', $today, 'bot.ai_crawler', 7);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'accesslog', $today->modify('-1 day'), 'bot.ai_crawler', 2);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'accesslog', $today, 'bot.search', 3);

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30), 'machine.summary.accesslog');

        self::assertSame(12, $tile->value);
        // The sparkline describes the total the tile shows, not whichever
        // bucket happened to be read first.
        self::assertSame(10, $tile->sparkline[$today->format('Y-m-d')]);
        self::assertSame(2, $tile->sparkline[$today->modify('-1 day')->format('Y-m-d')]);

        $byLabel = array_column($tile->breakdown, 'formattedValue', 'label');
        self::assertSame('9', $byLabel['AI crawlers']);
        self::assertSame('3', $byLabel['Search indexers']);
        // Every bucket is listed, including the ones at zero: an absent row
        // and a row reading 0 answer different questions.
        self::assertCount(5, $tile->breakdown);
    }

    public function testASiteWithoutMachineMetricsGetsNoSummaryTile(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');

        $identifiers = array_map(
            static fn (MetricTile $tile): string => $tile->identifier,
            $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30)->tiles,
        );

        self::assertNotContains('machine.summary.accesslog', $identifiers);
    }

    public function testTheVisitsTileCarriesItsChannelSplitAsItsParts(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.visits', 100);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.visits', 40, 'channel:search');
        $repository->upsert(2, 0, 'matomo', $today->modify('-1 day'), 'matomo.visits', 5, 'channel:search');
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.visits', 25, 'channel:direct');

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30), 'matomo.visits');

        // The headline is the stored total, never the sum of the parts: the
        // parts are what the provider could attribute, and a provider that
        // attributes none of it still has a correct tile.
        self::assertSame(100, $tile->value);
        self::assertSame(
            [
                ['label' => 'Direct', 'formattedValue' => '25'],
                ['label' => 'Search', 'formattedValue' => '45'],
            ],
            $tile->breakdown,
        );
    }

    public function testTheChannelSplitIsListedInAFixedOrderWithItsOwnLabels(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);

        foreach (['other' => 1, 'campaign' => 2, 'referral' => 3, 'social' => 4, 'search' => 5, 'direct' => 6] as $channel => $value) {
            $repository->upsert(2, 0, 'matomo', $today, 'matomo.visits', $value, 'channel:'.$channel);
        }

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30), 'matomo.visits');

        // Always the same order, never the order the rows happen to arrive
        // in, so a reader can find a channel in the same place every time.
        self::assertSame(
            ['Direct', 'Search', 'Social', 'Referral', 'Campaign', 'Other'],
            array_column($tile->breakdown, 'label'),
        );
    }

    public function testATileWithoutChannelRowsHasNoBreakdownAtAll(): void
    {
        // A provider that returns no referrer data leaves the popover
        // exactly as it was, with no empty section in it.
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.visits', 100);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.visits', 20, 'via_redirect');

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30), 'matomo.visits');

        self::assertSame([], $tile->breakdown);
        // The redirect share is read from its own dimension and is not part
        // of the split.
        self::assertSame(20, $tile->redirectSharePercent);
    }

    public function testAChannelNameNobodyDeclaredIsShownRatherThanSwallowed(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/');
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.visits', 9, 'channel:carrier-pigeon');

        $tile = $this->tileFor($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30), 'matomo.visits');

        self::assertSame([['label' => 'carrier-pigeon', 'formattedValue' => '9']], $tile->breakdown);
    }

    private function writeSiteConfigurationWithAccessLog(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/', [
            'accesslog' => ['pathPattern' => '/var/log/nginx/access.log'],
        ]);
    }

    private function tileFor(PageMetricsViewData $viewData, string $identifier): MetricTile
    {
        foreach ($viewData->tiles as $tile) {
            if ($tile->identifier === $identifier) {
                return $tile;
            }
        }

        self::fail(sprintf('No tile found for metric identifier "%s".', $identifier));
    }

    private function writeSiteConfigurationWithTwoProviders(): void
    {
        $this->writeSiteConfiguration(1, 'https://example.com/', [
            'matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x'],
            'accesslog' => ['pathPattern' => '/var/log/nginx/access.log'],
        ]);
    }

    /**
     * @param array<string, mixed>|null $providers defaults to Matomo alone, the shape every test but the
     *                                             multi-provider freshness ones needs
     */
    private function writeSiteConfiguration(int $rootPageId, string $base, ?array $providers = null): void
    {
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => $rootPageId,
            'base' => $base,
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
            'pageMetrics' => ['providers' => $providers ?? ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']]],
        ]);
    }
}
