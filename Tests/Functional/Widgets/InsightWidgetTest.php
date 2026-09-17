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
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, ImportStateRepository};
use KonradMichalik\Typo3PageMetrics\Widgets\InsightWidget;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * InsightWidgetTest.
 *
 * The widget's whole claim is that a card is a statement somebody can read
 * out loud, so these tests assert the rendered sentences rather than the
 * data behind them - a sentence that assembles its arguments in the wrong
 * order is exactly the failure the value objects alone cannot show.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class InsightWidgetTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];
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

    /**
     * The one that matters: a widget which always finds something to say is
     * one nobody believes, so "nothing cleared the threshold" has to reach
     * the reader as no card at all rather than as a card about a wobble.
     */
    public function testSaysNothingWhenNothingMovedSignificantly(): void
    {
        // Four views on a hundred: under both halves of the pageviews
        // threshold (a tenth, and ten views).
        $this->record(2, 'matomo.pageviews', $this->inCurrentPeriod(), 104);
        $this->record(2, 'matomo.pageviews', $this->inPreviousPeriod(), 100);

        $content = $this->renderWidget();

        self::assertStringNotContainsString('pagemetrics-insight-card', $content);
        self::assertStringContainsString('Nothing moved significantly', $content);
    }

    public function testStatesTheSitesOwnMovementAsASentence(): void
    {
        $this->record(2, 'matomo.pageviews', $this->inCurrentPeriod(), 130);
        $this->record(2, 'matomo.pageviews', $this->inPreviousPeriod(), 100);

        $content = $this->renderWidget();

        self::assertStringContainsString('Page views, 30 days: 130, up 30% against the previous period.', $content);
    }

    public function testNamesTheBiggestRiserAndTheBiggestFallerByTitle(): void
    {
        // The site total stays flat on purpose, so only the two page
        // movements are left to state.
        $this->record(2, 'matomo.pageviews', $this->inCurrentPeriod(), 200);
        $this->record(2, 'matomo.pageviews', $this->inPreviousPeriod(), 100);
        $this->record(5, 'matomo.pageviews', $this->inCurrentPeriod(), 100);
        $this->record(5, 'matomo.pageviews', $this->inPreviousPeriod(), 200);

        $content = $this->renderWidget();

        self::assertStringContainsString('Biggest riser by Page views', $content);
        self::assertStringContainsString('About', $content);
        self::assertStringContainsString('up 100% to 200.', $content);
        self::assertStringContainsString('Biggest faller by Page views', $content);
        self::assertStringContainsString('Team', $content);
        self::assertStringContainsString('down 50% to 100.', $content);
    }

    /**
     * The direction is in the sentence, not only in the icon beside it, and
     * the icon is marked decorative. A reader who cannot see the accent
     * colour loses nothing.
     */
    public function testCarriesTheDirectionInWordsAndMarksTheIconDecorative(): void
    {
        $this->record(2, 'matomo.pageviews', $this->inCurrentPeriod(), 130);
        $this->record(2, 'matomo.pageviews', $this->inPreviousPeriod(), 100);

        $content = $this->renderWidget();

        self::assertStringContainsString('up 30%', $content);
        self::assertStringContainsString('pagemetrics-insight-card-icon" aria-hidden="true"', $content);
    }

    public function testMakesNoClaimWhenThePreviousPeriodWasNeverImported(): void
    {
        $this->record(2, 'matomo.pageviews', $this->inCurrentPeriod(), 130);
        $this->record(2, 'matomo.pageviews', $this->inPreviousPeriod(), 100);
        $today = new DateTimeImmutable('today');
        // Imported window starts inside the current period, so the hundred
        // views the rise is measured against are days nobody fetched.
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today->modify('-1 day'), new DateRange($today->modify('-10 days'), $today->modify('-1 day')), true);

        $content = $this->renderWidget();

        self::assertStringNotContainsString('pagemetrics-insight-card', $content);
        self::assertStringContainsString('Nothing moved significantly', $content);
    }

    /**
     * A scheduler that stopped a week ago leaves the current period short of
     * seven days of rows. Reported as a fall, that is the worst output this
     * widget can produce, because it reads as a real one.
     */
    public function testMakesNoClaimWhenTheImportStoppedInsideTheCurrentPeriod(): void
    {
        $this->record(2, 'matomo.pageviews', $this->inCurrentPeriod(), 130);
        $this->record(2, 'matomo.pageviews', $this->inPreviousPeriod(), 100);
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today->modify('-7 days'), new DateRange($today->modify('-90 days'), $today->modify('-7 days')), true);

        $content = $this->renderWidget();

        self::assertStringNotContainsString('pagemetrics-insight-card', $content);
    }

    /**
     * Yesterday is the last day any surface of this extension has numbers
     * for, so a window ending there is a healthy import and not a stalled
     * one.
     */
    public function testStillSpeaksForAnImportThatHasNotRunForTodayYet(): void
    {
        $this->record(2, 'matomo.pageviews', $this->inCurrentPeriod(), 130);
        $this->record(2, 'matomo.pageviews', $this->inPreviousPeriod(), 100);
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today->modify('-1 day'), new DateRange($today->modify('-90 days'), $today->modify('-1 day')), true);

        $content = $this->renderWidget();

        self::assertStringContainsString('up 30% against the previous period.', $content);
    }

    /**
     * The container hands the tagged providers over in alphabetical order,
     * not in the order Services.yaml lists them, so a site running the
     * access log beside Matomo is asked about the access log first. Pared
     * down to the bot buckets - which is the pairing the provider docs
     * recommend - it counts no audience at all, and the site's primary
     * metric lives in the provider behind it. Speaking only for the first
     * one leaves this widget silent about a site every other surface has
     * plenty to say about.
     */
    public function testSpeaksForTheProviderHoldingThePrimaryMetricEvenWhenAnotherComesFirst(): void
    {
        $this->writeSiteConfigurationWithMachineOnlyAccessLog();
        $this->record(2, 'matomo.pageviews', $this->inCurrentPeriod(), 130);
        $this->record(2, 'matomo.pageviews', $this->inPreviousPeriod(), 100);

        $content = $this->renderWidget();

        self::assertStringContainsString('Page views, 30 days: 130, up 30% against the previous period.', $content);
    }

    public function testRendersTheEmptyStateWhenNoSiteHasAProvider(): void
    {
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                0 => ['title' => 'English', 'enabled' => true, 'languageId' => 0, 'base' => '/', 'locale' => 'en_US.UTF-8', 'navigationTitle' => 'English', 'flag' => 'us'],
            ],
            'errorHandling' => [],
            'routes' => [],
        ]);

        $content = $this->renderWidget();

        self::assertStringContainsString('callout-info', $content);
        self::assertStringNotContainsString('pagemetrics-insight-card', $content);
    }

    public function testDeclaresItsBackendAssetsAndNoLegacyOptions(): void
    {
        $widget = $this->get('dashboard.widget.pageMetricsInsight');
        self::assertInstanceOf(InsightWidget::class, $widget);

        self::assertSame(['EXT:typo3_page_metrics/Resources/Public/Css/page-metrics.css'], $widget->getCssFiles());
        self::assertSame([], $widget->getOptions());
    }

    private function renderWidget(): string
    {
        $request = (new ServerRequest('https://example.com/typo3/module/dashboard'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $widget = $this->get('dashboard.widget.pageMetricsInsight');
        self::assertInstanceOf(InsightWidget::class, $widget);
        $widget->setRequest($request);

        return $widget->renderWidgetContent();
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

    private function writeSiteConfiguration(): void
    {
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                0 => ['title' => 'English', 'enabled' => true, 'languageId' => 0, 'base' => '/', 'locale' => 'en_US.UTF-8', 'navigationTitle' => 'English', 'flag' => 'us'],
            ],
            'errorHandling' => [],
            'routes' => [],
            'pageMetrics' => ['providers' => ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']]],
        ]);
    }

    /**
     * Two providers, and the one the container asks first counts nothing a
     * reader is in the widget for.
     */
    private function writeSiteConfigurationWithMachineOnlyAccessLog(): void
    {
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                0 => ['title' => 'English', 'enabled' => true, 'languageId' => 0, 'base' => '/', 'locale' => 'en_US.UTF-8', 'navigationTitle' => 'English', 'flag' => 'us'],
            ],
            'errorHandling' => [],
            'routes' => [],
            'pageMetrics' => ['providers' => [
                'matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x'],
                'accesslog' => ['pathPattern' => '/var/log/nowhere/access.log*', 'metrics' => ['machine']],
            ]],
        ]);
    }
}
