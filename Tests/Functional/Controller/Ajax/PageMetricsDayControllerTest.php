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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Controller\Ajax;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Controller\Ajax\PageMetricsDayController;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange};
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, ImportStateRepository};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * PageMetricsDayControllerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageMetricsDayControllerTest extends FunctionalTestCase
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

    public function testListsEveryMarkerThatExplainsTheDay(): void
    {
        $this->writeSiteConfiguration();
        $day = (new DateTimeImmutable('today'))->modify('-3 days');
        $this->get(AnnotationRepository::class)->upsert('main', 'typo3', new Annotation('1', $day, 'Homepage rewritten', AnnotationCategory::Content, null, 2));
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', new Annotation('2', $day->modify('-5 days'), 'Old release', AnnotationCategory::Release));

        $body = (string) $this->dispatch(['id' => '2', 'date' => $day->format('Y-m-d')])->getBody();

        self::assertStringContainsString('Homepage rewritten', $body);
        self::assertStringContainsString('Content change', $body);
        self::assertStringNotContainsString('Old release', $body);
    }

    public function testShowsWhatEachMetricDidOnThatDayAlone(): void
    {
        $this->writeSiteConfiguration();
        $day = (new DateTimeImmutable('today'))->modify('-3 days');
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $day, 'matomo.pageviews', 12);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $day->modify('-1 day'), 'matomo.pageviews', 99);

        $body = (string) $this->dispatch(['id' => '2', 'date' => $day->format('Y-m-d')])->getBody();

        self::assertStringContainsString('Page views', $body);
        self::assertStringContainsString('>12<', $body);
        self::assertStringNotContainsString('>99<', $body);
    }

    public function testADayOutsideTheImportedWindowHasNoValueRatherThanAZeroOne(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today->modify('-1 day'), new DateRange($today->modify('-10 days'), $today->modify('-1 day')), true);

        $body = (string) $this->dispatch(['id' => '2', 'date' => $today->modify('-40 days')->format('Y-m-d')])->getBody();

        self::assertStringContainsString('No data', $body);
    }

    public function testComparesTheDaysBeforeAMarkerWithTheDaysAfterIt(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $day = $today->modify('-20 days');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today->modify('-1 day'), new DateRange($today->modify('-90 days'), $today->modify('-1 day')), true);
        $this->get(AnnotationRepository::class)->upsert('main', 'typo3', new Annotation('1', $day, 'Homepage rewritten', AnnotationCategory::Content, null, 2));
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $day->modify('-2 days'), 'matomo.pageviews', 41);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $day->modify('+2 days'), 'matomo.pageviews', 73);

        $body = (string) $this->dispatch(['id' => '2', 'date' => $day->format('Y-m-d')])->getBody();

        self::assertStringContainsString('pagemetrics-day-comparison', $body);
        self::assertStringContainsString('>41<', $body);
        self::assertStringContainsString('>73<', $body);
        // The same trend badge every other comparison in this extension uses,
        // spelled-out movement included.
        self::assertStringContainsString('pagemetrics-trend-positive', $body);
        self::assertStringContainsString('78%', $body);
        self::assertStringContainsString('Improved by', $body);
        self::assertStringNotContainsString('Not enough data', $body);
    }

    public function testSaysSoInsteadOfComparingAgainstDaysNobodyHasImportedYet(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $day = $today->modify('-3 days');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today->modify('-1 day'), new DateRange($today->modify('-90 days'), $today->modify('-1 day')), true);
        $this->get(AnnotationRepository::class)->upsert('main', 'typo3', new Annotation('1', $day, 'Homepage rewritten', AnnotationCategory::Content, null, 2));
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $day->modify('-2 days'), 'matomo.pageviews', 41);
        $this->get(DailyMetricsRepository::class)->upsert(2, 0, 'matomo', $day->modify('+1 day'), 'matomo.pageviews', 73);

        $body = (string) $this->dispatch(['id' => '2', 'date' => $day->format('Y-m-d')])->getBody();

        self::assertStringContainsString('Not enough data', $body);
        self::assertStringNotContainsString('>73<', $body);
    }

    public function testASiteWideMarkerIsNotAClaimAboutThisPage(): void
    {
        $this->writeSiteConfiguration();
        $today = new DateTimeImmutable('today');
        $day = $today->modify('-20 days');
        $this->get(ImportStateRepository::class)->markImported('matomo', 'main', $today->modify('-1 day'), new DateRange($today->modify('-90 days'), $today->modify('-1 day')), true);
        $this->get(AnnotationRepository::class)->upsert('main', 'matomo', new Annotation('1', $day, 'Release 2.0'));

        $body = (string) $this->dispatch(['id' => '2', 'date' => $day->format('Y-m-d')])->getBody();

        self::assertStringContainsString('Release 2.0', $body);
        self::assertStringNotContainsString('pagemetrics-day-comparison', $body);
    }

    public function testRendersNothingForADateTheRequestMadeUp(): void
    {
        $this->writeSiteConfiguration();

        self::assertSame('', (string) $this->dispatch(['id' => '2', 'date' => '2026-13-45'])->getBody());
        self::assertSame('', (string) $this->dispatch(['id' => '2', 'date' => 'yesterday'])->getBody());
        self::assertSame('', (string) $this->dispatch(['id' => '2'])->getBody());
    }

    public function testRendersNothingWhenNoPageIsSelected(): void
    {
        $this->writeSiteConfiguration();

        self::assertSame('', (string) $this->dispatch(['id' => '0', 'date' => (new DateTimeImmutable('today'))->format('Y-m-d')])->getBody());
    }

    public function testRendersNothingWhenTheBackendUserLacksPageAccess(): void
    {
        // Page 3 in the fixture has perms_everybody = 0, so the editor has no
        // PAGE_SHOW permission on it even though the site resolves it fine.
        // The uid arrives from the request, so this is the check that keeps a
        // hand-built one from paying off.
        $this->writeSiteConfiguration();
        $backendUser = $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $body = (string) $this->dispatch(['id' => '3', 'date' => (new DateTimeImmutable('today'))->format('Y-m-d')])->getBody();

        self::assertSame('', $body);
    }

    public function testRendersNothingWhenTheBackendUsersTsConfigDisablesAccess(): void
    {
        // The same defense in depth the tiles route has: this route is
        // reachable directly, so it enforces the access decision itself
        // rather than trusting that no placeholder ever loaded it.
        $this->writeSiteConfiguration();
        $backendUser = $this->setUpBackendUser(3);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $body = (string) $this->dispatch(['id' => '2', 'date' => (new DateTimeImmutable('today'))->format('Y-m-d')])->getBody();

        self::assertSame('', $body);
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function dispatch(array $queryParams): ResponseInterface
    {
        return $this->get(PageMetricsDayController::class)->handleRequest($this->buildRequest($queryParams));
    }

    /**
     * Typed as the interface, which is what the route dispatcher hands the
     * controller: the middleware stack decides the implementation, and a
     * test that pins the concrete one would describe a contract the
     * controller does not have.
     *
     * @param array<string, string> $queryParams
     */
    private function buildRequest(array $queryParams): ServerRequestInterface
    {
        $route = new Route('/page-metrics/day', ['packageName' => 'konradmichalik/typo3-page-metrics']);
        $request = (new ServerRequest('https://example.com/typo3/ajax/page-metrics/day'))
            ->withQueryParams($queryParams)
            ->withAttribute('route', $route)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
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
            'pageMetrics' => [
                'providers' => ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']],
            ],
        ]);
    }
}
