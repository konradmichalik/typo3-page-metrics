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
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, Insight};
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, ImportStateRepository};
use KonradMichalik\Typo3PageMetrics\Service\InsightBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function array_filter;
use function array_map;

/**
 * InsightBuilderTest.
 *
 * Covers the two rules the rendered widget cannot show on its own: how a
 * site-level statement is ordered against a page-level one, and how two
 * metrics measured in different units (views against percentage points) are
 * ranked against each other at all.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class InsightBuilderTest extends FunctionalTestCase
{
    private const PERIOD_DAYS = 30;

    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private InsightBuilder $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Widgets/Fixtures/insight-pages.csv');
        $this->subject = $this->get(InsightBuilder::class);
    }

    public function testReturnsNothingWhenNoMovementClearsItsThreshold(): void
    {
        $this->writeSite('main', 1);
        $this->record(2, 'matomo.pageviews', $this->inCurrentPeriod(), 104);
        $this->record(2, 'matomo.pageviews', $this->inPreviousPeriod(), 100);

        self::assertSame([], $this->build());
    }

    /**
     * The stated ordering rule. A page that rose 46% inside a site that rose
     * 40% rose with the site, and a reader cannot tell that apart from a
     * page that rose on its own until the site's own line has been read - so
     * the frame precedes the detail whatever the two scores are.
     */
    public function testRanksTheSitesOwnMovementAboveAHigherScoringPageMovement(): void
    {
        $this->writeSite('main', 1);
        $this->record(2, 'matomo.pageviews', $this->inPreviousPeriod(), 1000);
        $this->record(2, 'matomo.pageviews', $this->inCurrentPeriod(), 1100);
        $this->record(3, 'matomo.pageviews', $this->inPreviousPeriod(), 100);
        $this->record(3, 'matomo.pageviews', $this->inCurrentPeriod(), 300);

        $insights = $this->build();

        self::assertCount(2, $insights);
        self::assertTrue($insights[0]->isSiteLevel());
        self::assertFalse($insights[1]->isSiteLevel());
        // And the page really did outscore the site, so the order above is
        // the rule at work and not an accident of the numbers.
        self::assertGreaterThan($insights[0]->score, $insights[1]->score);
    }

    /**
     * Views and percentage points have no common unit, so the ranking cannot
     * compare the movements themselves. It compares how many times over its
     * own MetricSignificance each one went, which is a bare multiple either
     * way.
     */
    public function testRanksTwoMetricsOfDifferentUnitsByHowFarEachClearedItsOwnThreshold(): void
    {
        $this->writeSite('main', 1);
        $this->record(2, 'matomo.pageviews', $this->inPreviousPeriod(), 100);
        $this->record(2, 'matomo.pageviews', $this->inCurrentPeriod(), 130);
        // Bounce rate from 20% to 60%: forty percentage points against a
        // three-point floor, against thirty views on a ten-view one.
        $this->record(2, 'matomo.entries', $this->inPreviousPeriod(), 100);
        $this->record(2, 'matomo.entry_bounces', $this->inPreviousPeriod(), 20);
        $this->record(2, 'matomo.entries', $this->inCurrentPeriod(), 100);
        $this->record(2, 'matomo.entry_bounces', $this->inCurrentPeriod(), 60);

        $siteLevel = array_values(array_filter($this->build(), static fn (Insight $insight): bool => $insight->isSiteLevel()));

        self::assertCount(2, $siteLevel);
        self::assertSame('Bounce rate', $siteLevel[0]->sentenceArguments[0]);
        self::assertSame('Page views', $siteLevel[1]->sentenceArguments[0]);
    }

    /**
     * Two fixed site-level slots, the primary metric and the single most
     * significant other one. A widget whose four cards were four metrics of
     * one site would have stopped being about what changed and started
     * being a second tile row.
     */
    public function testStatesAtMostOneOtherMetricBesideThePrimaryOne(): void
    {
        $this->writeSite('main', 1);
        $this->record(2, 'matomo.pageviews', $this->inPreviousPeriod(), 100);
        $this->record(2, 'matomo.pageviews', $this->inCurrentPeriod(), 130);
        $this->record(2, 'matomo.visits', $this->inPreviousPeriod(), 100);
        $this->record(2, 'matomo.visits', $this->inCurrentPeriod(), 200);
        $this->record(2, 'matomo.entries', $this->inPreviousPeriod(), 100);
        $this->record(2, 'matomo.entry_bounces', $this->inPreviousPeriod(), 20);
        $this->record(2, 'matomo.entries', $this->inCurrentPeriod(), 100);
        $this->record(2, 'matomo.entry_bounces', $this->inCurrentPeriod(), 60);

        $labels = array_map(
            static fn (Insight $insight): mixed => $insight->sentenceArguments[0],
            array_values(array_filter($this->build(), static fn (Insight $insight): bool => $insight->isSiteLevel())),
        );

        self::assertSame(['Bounce rate', 'Page views'], $labels);
    }

    /**
     * The cap is the widget's, not each site's. Four cards spread over two
     * sites is not four cards about anything.
     */
    public function testCapsTheWholeListAtFourCardsAcrossEverySite(): void
    {
        $this->writeSite('main', 1);
        $this->writeSite('second', 10);

        foreach ([2 => 'main', 11 => 'second'] as $pageUid => $unused) {
            $this->record($pageUid, 'matomo.pageviews', $this->inPreviousPeriod(), 100);
            $this->record($pageUid, 'matomo.pageviews', $this->inCurrentPeriod(), 130);
            $this->record($pageUid, 'matomo.entries', $this->inPreviousPeriod(), 100);
            $this->record($pageUid, 'matomo.entry_bounces', $this->inPreviousPeriod(), 20);
            $this->record($pageUid, 'matomo.entries', $this->inCurrentPeriod(), 100);
            $this->record($pageUid, 'matomo.entry_bounces', $this->inCurrentPeriod(), 60);
        }

        $insights = $this->build();

        self::assertCount(4, $insights);
        foreach ($insights as $insight) {
            self::assertTrue($insight->isSiteLevel());
        }
    }

    public function testDropsOneSiteEntirelyWithoutSilencingTheOther(): void
    {
        $this->writeSite('main', 1);
        $this->writeSite('second', 10);
        $today = new DateTimeImmutable('today');
        // "main" has only ever imported the last ten days, so the period its
        // rise would be measured against was never fetched.
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today->modify('-1 day'), new DateRange($today->modify('-10 days'), $today->modify('-1 day')), true);

        foreach ([2, 11] as $pageUid) {
            $this->record($pageUid, 'matomo.pageviews', $this->inPreviousPeriod(), 100);
            $this->record($pageUid, 'matomo.pageviews', $this->inCurrentPeriod(), 130);
        }

        $identifiers = array_map(static fn (Insight $insight): string => $insight->siteIdentifier, $this->build());

        self::assertNotContains('main', $identifiers);
        self::assertContains('second', $identifiers);
    }

    /**
     * @return list<Insight>
     */
    private function build(): array
    {
        return $this->subject->build($this->get(SiteFinder::class)->getAllSites(), self::PERIOD_DAYS);
    }

    private function record(int $pageUid, string $metric, DateTimeImmutable $date, int $value): void
    {
        $this->get(DailyMetricsRepository::class)->upsert($pageUid, 0, 'matomo', $date, $metric, $value);
    }

    private function inCurrentPeriod(): DateTimeImmutable
    {
        return new DateTimeImmutable('today -1 day');
    }

    private function inPreviousPeriod(): DateTimeImmutable
    {
        return new DateTimeImmutable('today -40 days');
    }

    private function writeSite(string $identifier, int $rootPageId): void
    {
        $this->get(SiteWriter::class)->write($identifier, [
            'rootPageId' => $rootPageId,
            'base' => 'https://'.$identifier.'.example.com/',
            'languages' => [
                0 => ['title' => 'English', 'enabled' => true, 'languageId' => 0, 'base' => '/', 'locale' => 'en_US.UTF-8', 'navigationTitle' => 'English', 'flag' => 'us'],
            ],
            'errorHandling' => [],
            'routes' => [],
            'pageMetrics' => ['providers' => ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']]],
        ]);
    }
}
