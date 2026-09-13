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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Widgets;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Persistence\DailyMetricsRepository;
use KonradMichalik\Typo3PageMetrics\Widgets\TopPagesWidget;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Settings\SettingsInterface;
use TYPO3\CMS\Dashboard\Factory\WidgetSettingsFactory;
use TYPO3\CMS\Dashboard\Widgets\{WidgetConfigurationInterface, WidgetContext, WidgetRendererInterface};
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function interface_exists;
use function strpos;

/**
 * TopPagesGoalColumnTest.
 *
 * The goal column of "Most viewed pages": which goals it offers, what it
 * shows beside the traffic it ranks by, and what it leaves alone. Its own
 * file rather than more cases in SettingsAwareWidgetsTest, because every one
 * of these needs a site that declares goals and none of that file's cases
 * does.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TopPagesGoalColumnTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        // Skipping has to happen after parent::setUp(), see the same comment
        // in SettingsAwareWidgetsTest.
        parent::setUp();

        if (!interface_exists(WidgetRendererInterface::class)) {
            self::markTestSkipped('Settings-aware widgets require TYPO3 14 (WidgetRendererInterface).');
        }

        $this->importCSVDataSet(__DIR__.'/../Service/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/../Service/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    public function testOffersNoGoalSettingToASiteWithoutGoals(): void
    {
        $this->writeSiteConfiguration([]);

        $definitions = $this->widget()->getSettingsDefinitions();

        self::assertCount(2, $definitions);
        self::assertSame(['level', 'includeSubpages'], array_map(static fn ($definition): string => $definition->key, $definitions));
    }

    public function testOffersEachConfiguredGoalAsASetting(): void
    {
        $this->writeSiteConfiguration([
            ['provider' => 'matomo', 'id' => 3, 'label' => 'Newsletter signup'],
        ]);

        $definitions = $this->widget()->getSettingsDefinitions();

        self::assertCount(3, $definitions);
        self::assertSame('goalMetric', $definitions[2]->key);
        self::assertArrayHasKey('', $definitions[2]->enum);
        self::assertSame('Newsletter signup', $definitions[2]->enum['matomo.goal.3.conversions']);
    }

    public function testShowsTheGoalsConversionsBesideTheViews(): void
    {
        $this->writeSiteConfiguration([
            ['provider' => 'matomo', 'id' => 3, 'label' => 'Newsletter signup'],
        ]);
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 20);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.goal.3.conversions', 7);

        $content = $this->render(['goalMetric' => 'matomo.goal.3.conversions']);

        self::assertStringContainsString('Newsletter signup', $content);
        self::assertMatchesRegularExpression('/\b7\b/', $content);
    }

    public function testShowsAZeroForAPageThatConvertedNothing(): void
    {
        $this->writeSiteConfiguration([
            ['provider' => 'matomo', 'id' => 3, 'label' => 'Newsletter signup'],
        ]);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 20);

        $content = $this->render(['goalMetric' => 'matomo.goal.3.conversions']);

        // A zero rather than an empty cell: the importers store no row for a
        // page that converted nothing, so the column states what was
        // measured instead of leaving a gap that reads as "unknown".
        self::assertStringContainsString('Newsletter signup', $content);
        self::assertMatchesRegularExpression('#<td[^>]*>\s*0\s*</td>#', $content);
    }

    public function testShowsNoGoalColumnUntilOneIsPicked(): void
    {
        $this->writeSiteConfiguration([
            ['provider' => 'matomo', 'id' => 3, 'label' => 'Newsletter signup'],
        ]);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 20);

        $content = $this->render([]);

        self::assertStringNotContainsString('Newsletter signup', $content);
    }

    public function testKeepsTheRankingOnTrafficBesideTheGoalColumn(): void
    {
        $this->writeSiteConfiguration([
            ['provider' => 'matomo', 'id' => 3, 'label' => 'Newsletter signup'],
        ]);
        $today = new DateTimeImmutable('today');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 20);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.goal.3.conversions', 1);
        $this->get(DailyMetricsRepository::class)->upsert(5, 0, 'matomo', $today, 'matomo.pageviews', 5);
        $this->get(DailyMetricsRepository::class)->upsert(5, 0, 'matomo', $today, 'matomo.goal.3.conversions', 9);

        $content = $this->render(['goalMetric' => 'matomo.goal.3.conversions']);

        self::assertLessThan(strpos($content, 'Team'), strpos($content, 'About'));
    }

    public function testIgnoresAGoalAnotherSiteDeclared(): void
    {
        $this->writeSiteConfiguration([
            ['provider' => 'matomo', 'id' => 3, 'label' => 'Newsletter signup'],
        ]);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', new DateTimeImmutable('today'), 'matomo.pageviews', 20);

        $content = $this->render(['goalMetric' => 'matomo.goal.99.conversions']);

        self::assertStringNotContainsString('Newsletter signup', $content);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function render(array $settings): string
    {
        $widget = $this->widget();
        $configuration = $this->get('pageMetricsTopPagesWidgetConfiguration');
        self::assertInstanceOf(WidgetConfigurationInterface::class, $configuration);

        $request = (new ServerRequest('https://example.com/typo3/module/dashboard'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $resolvedSettings = $this->get(WidgetSettingsFactory::class)->createSettings('pageMetricsTopPages', $settings, $widget->getSettingsDefinitions());
        self::assertInstanceOf(SettingsInterface::class, $resolvedSettings);

        return $widget->renderWidget(new WidgetContext('pageMetricsTopPages', [], $configuration, $resolvedSettings, $request))->content;
    }

    private function widget(): TopPagesWidget
    {
        $widget = $this->get('dashboard.widget.pageMetricsTopPages');
        self::assertInstanceOf(TopPagesWidget::class, $widget);

        return $widget;
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
