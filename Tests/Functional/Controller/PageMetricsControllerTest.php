<?php

declare(strict_types=1);

/*
 * This file is part of the "page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Controller;

use KonradMichalik\Typo3PageMetrics\Controller\PageMetricsController;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PageMetricsControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    public function testRendersNeutralStateWhenNoPageIsSelected(): void
    {
        $controller = $this->get(PageMetricsController::class);
        // BackendViewFactory::create() reads the current route from the request
        // attribute to resolve template paths, and ModuleTemplate's PageRenderer
        // setup needs applicationType/normalizedParams; the real backend router
        // adds all three during dispatch, so a functional test invoking the
        // controller directly has to fake them.
        $route = new Route('/module/web/pagemetrics', ['packageName' => 'konradmichalik/typo3-page-metrics']);
        $request = (new ServerRequest('https://example.com/typo3/module/web/pagemetrics'))
            ->withQueryParams(['id' => '0'])
            ->withAttribute('route', $route)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $response = $controller->handleRequest($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Select a page', (string) $response->getBody());
    }
}
