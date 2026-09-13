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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Templates;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\ModuleView;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, ImportStateRepository};
use KonradMichalik\Typo3PageMetrics\Service\PeriodComparisonBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\View\{ViewFactoryData, ViewFactoryInterface};
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * ModuleCompareTemplateTest.
 *
 * The compare view rendered for real, because everything this view decides
 * is a decision about what is drawn: a movement that cleared its threshold
 * gets the badge, one that did not gets plain text, and a period nobody
 * imported gets neither. A builder assertion cannot tell those apart.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ModuleCompareTemplateTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Service/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/../Service/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $this->writeSiteConfiguration();
    }

    public function testASignificantMovementIsBadgedAndNamed(): void
    {
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 130);
        $repository->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.pageviews', 100);

        $html = $this->render();

        self::assertStringContainsString('pagemetrics-trend', $html);
        self::assertStringContainsString('Significant change', $html);
        self::assertStringContainsString('130', $html);
        self::assertStringContainsString('+30', $html);
    }

    public function testAMovementUnderTheThresholdIsNeitherBadgedNorCalledSignificant(): void
    {
        // The whole point of the view: five views on a hundred must not look
        // like thirty on a hundred.
        $today = new DateTimeImmutable('today');
        $repository = $this->get(DailyMetricsRepository::class);
        $repository->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 105);
        $repository->upsert(2, 0, 'matomo', $today->modify('-10 days'), 'matomo.pageviews', 100);

        $html = $this->render();

        self::assertStringContainsString('Within normal variation', $html);
        self::assertStringNotContainsString('Significant change', $html);
        self::assertStringContainsString('pagemetrics-quiet', $html);
    }

    public function testAPeriodNobodyImportedIsSaidInWordsRatherThanDrawnAsAChange(): void
    {
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today, new DateRange($today->modify('-6 days'), $today), true);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $today, 'matomo.pageviews', 130);

        $html = $this->render();

        self::assertStringContainsString('Not comparable, the period was never imported', $html);
        self::assertStringNotContainsString('pagemetrics-trend', $html);
    }

    public function testThePickerOffersEveryBaselineAndMarksTheActiveOneInMoreThanColour(): void
    {
        $html = $this->render('year');

        self::assertStringContainsString('The period immediately before', $html);
        self::assertStringContainsString('The same days one month earlier', $html);
        self::assertStringContainsString('The same days one quarter earlier', $html);
        self::assertStringContainsString('The same days one year earlier', $html);
        self::assertStringContainsString('aria-current="true"', $html);
        // The filled button is the active one, and the word beside it says so
        // where the fill is not seen at all.
        self::assertStringContainsString('btn-primary', $html);
        self::assertStringContainsString('compareBaseline=previous', $html);
    }

    public function testTheExplanationIsProseInTheViewRatherThanATooltip(): void
    {
        $html = $this->render();

        self::assertStringContainsString('counts as significant only when it clears both thresholds', $html);
        self::assertStringNotContainsString('data-hint-title', $html);
    }

    private function render(string $baselineKey = 'previous'): string
    {
        $request = (new ServerRequest('https://example.com/typo3/module/web/pagemetrics', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            ['EXT:typo3_page_metrics/Resources/Private/Templates/'],
            ['EXT:typo3_page_metrics/Resources/Private/Partials/'],
            [__DIR__.'/Fixtures/'],
            null,
            $request,
        ));
        $view->assignMultiple([
            'pageId' => 2,
            'period' => 7,
            'activeView' => ModuleView::Compare,
            'comparison' => $this->get(PeriodComparisonBuilder::class)->build(2, 0, 7, $baselineKey),
        ]);

        return $view->render('Module/Compare');
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
