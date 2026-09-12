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
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{MetricTile, PageMetricsViewData};
use KonradMichalik\Typo3PageMetrics\Persistence\DailyMetricsRepository;
use KonradMichalik\Typo3PageMetrics\Service\PageMetricsViewDataBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function array_map;
use function sprintf;

/**
 * GoalMetricTilesTest.
 *
 * Covers the one thing about goals that no provider unit test can: a metric
 * that exists only because a site configured it still has to reach the tile
 * row, which runs through PageMetricsViewDataBuilder and the real container
 * wiring rather than through the provider alone.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class GoalMetricTilesTest extends FunctionalTestCase
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

    public function testAConfiguredGoalBecomesATileOfItsOwn(): void
    {
        $this->writeSiteConfiguration([
            ['provider' => 'matomo', 'id' => 3, 'label' => 'Newsletter signup', 'revenue' => true],
        ]);

        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.goal.3.conversions', 4);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.goal.3.revenue', 1250);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);

        $conversions = $this->tileFor($viewData, 'matomo.goal.3.conversions');
        self::assertSame('Newsletter signup', $conversions->label);
        self::assertSame(4, $conversions->value);
        self::assertSame('4', $conversions->formattedValue);

        // Stored in minor units, read as money.
        $revenue = $this->tileFor($viewData, 'matomo.goal.3.revenue');
        self::assertSame(MetricUnit::Currency, $revenue->unit);
        self::assertSame(1250, $revenue->value);
        self::assertSame('12.50', $revenue->formattedValue);
    }

    public function testGoalTilesSitBehindTheMetricsEveryMatomoSiteHas(): void
    {
        $this->writeSiteConfiguration([
            ['provider' => 'matomo', 'id' => 3, 'label' => 'Newsletter signup'],
        ]);

        $identifiers = $this->tileIdentifiers($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30));

        self::assertSame(
            ['matomo.pageviews', 'matomo.visits', 'matomo.avg_time_on_page', 'matomo.bounce_rate', 'matomo.goal.3.conversions'],
            $identifiers,
        );
    }

    public function testASiteWithoutGoalsShowsExactlyTheTilesItAlwaysShowed(): void
    {
        $this->writeSiteConfiguration([]);

        $identifiers = $this->tileIdentifiers($this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30));

        self::assertSame(
            ['matomo.pageviews', 'matomo.visits', 'matomo.avg_time_on_page', 'matomo.bounce_rate'],
            $identifiers,
        );
    }

    /**
     * @return list<string>
     */
    private function tileIdentifiers(PageMetricsViewData $viewData): array
    {
        return array_map(static fn (MetricTile $tile): string => $tile->identifier, $viewData->tiles);
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

    /**
     * @param list<array<string, mixed>> $goals
     */
    private function writeSiteConfiguration(array $goals): void
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
            'pageMetrics' => [
                'providers' => ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']],
                'goals' => $goals,
            ],
        ]);
    }
}
