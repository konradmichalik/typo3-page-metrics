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

use KonradMichalik\Typo3PageMetrics\Controller\Ajax\PageMetricsPreferencesController;
use KonradMichalik\Typo3PageMetrics\Service\PageMetricsUserPreferences;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * PageMetricsPreferencesControllerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageMetricsPreferencesControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    public function testStoresTheCollapsedPreference(): void
    {
        $response = $this->dispatch(['collapsed' => '1']);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->get(PageMetricsUserPreferences::class)->isCollapsed());
    }

    public function testStoresTheExpandedPreference(): void
    {
        $this->get(PageMetricsUserPreferences::class)->setCollapsed(true);

        $response = $this->dispatch(['collapsed' => '0']);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->get(PageMetricsUserPreferences::class)->isCollapsed());
    }

    public function testRejectsARequestWithoutTheField(): void
    {
        $response = $this->dispatch([]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testRefusesAUserTheFeatureIsSwitchedOffFor(): void
    {
        // Whoever cannot see the tiles has no preference to express about
        // them, and the route is reachable directly.
        $backendUser = $this->setUpBackendUser(3);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $response = $this->dispatch(['collapsed' => '1']);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->get(PageMetricsUserPreferences::class)->isCollapsed());
    }

    /**
     * @param array<string, string> $body
     */
    private function dispatch(array $body): ResponseInterface
    {
        $route = new Route('/ajax/page-metrics/preferences', ['packageName' => 'typo3/cms-backend']);
        $request = (new ServerRequest('https://example.com/typo3/ajax/page-metrics/preferences', 'POST'))
            ->withParsedBody($body)
            ->withAttribute('route', $route)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        return $this->get(PageMetricsPreferencesController::class)->handleRequest($request);
    }
}
