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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricTotal};
use KonradMichalik\Typo3PageMetrics\Persistence\DailyMetricsRepository;
use KonradMichalik\Typo3PageMetrics\Service\SiteMetricValueReader;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function array_keys;

/**
 * SiteMetricValueReaderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SiteMetricValueReaderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $this->today = new DateTimeImmutable('today');
        $this->writeSiteConfiguration();
    }

    public function testACountIsSummedOverThePeriodAndKeepsItsOwnUnit(): void
    {
        $this->upsert(2, 'matomo.pageviews', $this->today, 40);
        $this->upsert(2, 'matomo.pageviews', $this->today->modify('-1 day'), 60);

        $total = $this->totals()['matomo.pageviews'];

        self::assertEquals(new MetricTotal(MetricUnit::Count, 100), $total);
    }

    public function testADurationIsSecondsAndNotTheStringATileWouldShow(): void
    {
        $this->upsert(2, 'matomo.time_spent_seconds', $this->today, 366);
        $this->upsert(2, 'matomo.visits', $this->today, 2);

        self::assertSame(183, $this->totals()['matomo.avg_time_on_page']->value);
        self::assertSame(MetricUnit::Seconds, $this->totals()['matomo.avg_time_on_page']->unit);
    }

    public function testARateIsTheWholePeriodsNumeratorOverItsWholeDenominator(): void
    {
        // Two days of four in ten and one in ten: 5 of 20 is 25 percent,
        // where averaging the two days would report 30.
        $this->upsert(2, 'matomo.entries', $this->today, 10);
        $this->upsert(2, 'matomo.entry_bounces', $this->today, 4);
        $this->upsert(2, 'matomo.entries', $this->today->modify('-1 day'), 10);
        $this->upsert(2, 'matomo.entry_bounces', $this->today->modify('-1 day'), 1);

        self::assertEquals(new MetricTotal(MetricUnit::Percent, 25), $this->totals()['matomo.bounce_rate']);
    }

    public function testAMetricWithNoRowsInThePeriodIsZeroRatherThanAbsent(): void
    {
        self::assertSame(0, $this->totals()['matomo.pageviews']->value);
    }

    public function testDaysOutsideTheRangeAreNotCounted(): void
    {
        $this->upsert(2, 'matomo.pageviews', $this->today, 5);
        $this->upsert(2, 'matomo.pageviews', $this->today->modify('-30 days'), 900);

        self::assertSame(5, $this->totals()['matomo.pageviews']->value);
    }

    public function testOnlyTheNamedPagesAreCounted(): void
    {
        $this->upsert(2, 'matomo.pageviews', $this->today, 5);
        $this->upsert(3, 'matomo.pageviews', $this->today, 900);

        self::assertSame(5, $this->totals()['matomo.pageviews']->value);
        self::assertSame(905, $this->totals([2, 3])['matomo.pageviews']->value);
    }

    public function testLeavesMachineTrafficOutBecauseNoSurfaceGivesItARowOfItsOwn(): void
    {
        foreach (array_keys($this->totals()) as $identifier) {
            self::assertStringNotContainsString('bot', $identifier);
        }
    }

    /**
     * @param list<int> $pageUids
     *
     * @return array<string, MetricTotal>
     */
    private function totals(array $pageUids = [2]): array
    {
        return $this->get(SiteMetricValueReader::class)->totals(
            $this->site(),
            $pageUids,
            0,
            new DateRange($this->today->modify('-6 days'), $this->today),
        );
    }

    private function site(): Site
    {
        return $this->get(SiteFinder::class)->getSiteByPageId(1);
    }

    private function upsert(int $pageUid, string $metric, DateTimeImmutable $date, int $value): void
    {
        $this->get(DailyMetricsRepository::class)->upsert($pageUid, 0, 'matomo', $date, $metric, $value);
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
