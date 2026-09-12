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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricPolarity, MetricUnit};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, RateMetricDefinition};
use KonradMichalik\Typo3PageMetrics\Persistence\DailyMetricsRepository;
use KonradMichalik\Typo3PageMetrics\Service\SiteBenchmark;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SiteBenchmarkTest.
 *
 * The median and the thresholds around it, exercised directly rather than
 * inferred from rendered tile markup: what a band is worth depends on how
 * many pages are behind it and on what the metric means, and neither of
 * those is visible in the markup once it has been decided.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SiteBenchmarkTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private SiteBenchmark $subject;
    private DailyMetricsRepository $dailyMetrics;
    private DateRange $range;

    protected function setUp(): void
    {
        parent::setUp();
        // Its own page set: the shared fixture has five pages, and the
        // thresholds under test need both a field large enough to divide
        // and a page left outside it.
        $this->importCSVDataSet(__DIR__.'/Fixtures/benchmark-pages.csv');
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [[
                'title' => 'English',
                'enabled' => true,
                'languageId' => 0,
                'base' => '/',
                'locale' => 'en_US.UTF-8',
                'navigationTitle' => 'English',
                'flag' => 'us',
            ]],
        ]);
        $this->subject = $this->get(SiteBenchmark::class);
        $this->dailyMetrics = $this->get(DailyMetricsRepository::class);
        $this->range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));
    }

    public function testReportsNothingWhileOnlyOnePageHasData(): void
    {
        // One page is the page comparing with itself.
        $this->upsert(2, 'matomo.pageviews', 10);

        self::assertNull($this->subject->forCountMetric($this->site(), 2, 0, $this->countMetric(), $this->range, 10));
    }

    public function testTheMedianIsTheTypicalPageNotTheSiteTotal(): void
    {
        foreach ([2 => 10, 3 => 20, 5 => 300] as $pageUid => $value) {
            $this->upsert($pageUid, 'matomo.pageviews', $value);
        }

        $result = $this->subject->forCountMetric($this->site(), 2, 0, $this->countMetric(), $this->range, 10);

        self::assertNotNull($result);
        self::assertSame(20, $result->median);
        self::assertSame(3, $result->pageCount);
    }

    public function testWithholdsTheBandUntilThereAreEnoughPagesToDivide(): void
    {
        // A fifth of four pages is not a fifth. The median still stands.
        foreach ([2 => 10, 3 => 20, 5 => 30, 6 => 40] as $pageUid => $value) {
            $this->upsert($pageUid, 'matomo.pageviews', $value);
        }

        $result = $this->subject->forCountMetric($this->site(), 2, 0, $this->countMetric(), $this->range, 10);

        self::assertNotNull($result);
        self::assertNull($result->band);
    }

    public function testWithholdsTheBandForAMetricWhereARiseIsNotAnImprovement(): void
    {
        // "In the top fifth" would mean the worst fifth here, and a phrase
        // that flips its meaning per metric is worse than no phrase.
        foreach ([2 => 10, 3 => 20, 5 => 30, 6 => 40, 7 => 50] as $pageUid => $value) {
            $this->upsert($pageUid, 'bot.ai_crawler', $value);
        }

        $result = $this->subject->forCountMetric($this->site(), 7, 0, $this->neutralMetric(), $this->range, 50);

        self::assertNotNull($result);
        self::assertNull($result->band);
    }

    public function testWithholdsTheBandForAPageTheImportHasNotReachedYet(): void
    {
        // Ranking a page that is not in the field puts it at the bottom of
        // one, reporting "not imported" as "worst on the site".
        foreach ([3 => 20, 5 => 30, 6 => 40, 7 => 50, 8 => 60] as $pageUid => $value) {
            $this->upsert($pageUid, 'matomo.pageviews', $value);
        }

        $result = $this->subject->forCountMetric($this->site(), 2, 0, $this->countMetric(), $this->range, 0);

        self::assertNotNull($result);
        self::assertNull($result->band);
        self::assertSame(40, $result->median);
    }

    public function testARateMedianIsTakenPerPageNotFromPooledSums(): void
    {
        // Page 2 bounces 1 of 4, page 3 bounces 300 of 400. Pooling gives
        // 75%, which is just the busy page's rate wearing the site's name;
        // the median of the two pages' own rates is 50%.
        $this->upsert(2, 'matomo.entry_bounces', 1);
        $this->upsert(2, 'matomo.entries', 4);
        $this->upsert(3, 'matomo.entry_bounces', 300);
        $this->upsert(3, 'matomo.entries', 400);

        $result = $this->subject->forRateMetric($this->site(), 2, 0, $this->rateMetric(), $this->range, 25);

        self::assertNotNull($result);
        self::assertSame(50, $result->median);
        self::assertNull($result->band);
    }

    private function upsert(int $pageUid, string $metric, int $value): void
    {
        $this->dailyMetrics->upsert($pageUid, 0, 'matomo', new DateTimeImmutable('2026-08-01'), $metric, $value);
    }

    private function site(): Site
    {
        return $this->get(SiteFinder::class)->getSiteByIdentifier('main');
    }

    private function countMetric(): MetricDefinition
    {
        return new MetricDefinition('matomo.pageviews', 'Page views', 'Views', 'actions-view', MetricUnit::Count, 10, '#0969da');
    }

    private function neutralMetric(): MetricDefinition
    {
        return new MetricDefinition('bot.ai_crawler', 'AI crawlers', 'Crawler hits', 'tabler-spider', MetricUnit::Count, 40, '#ae3ec9', MetricPolarity::Neutral);
    }

    private function rateMetric(): RateMetricDefinition
    {
        return new RateMetricDefinition('matomo.bounce_rate', 'Bounce rate', 'Bounces', 'actions-door-exit', MetricUnit::Percent, 40, '#cf222e', 'matomo.entry_bounces', 'matomo.entries', MetricPolarity::LowerIsBetter);
    }
}
