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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\EventListener;

use KonradMichalik\Typo3PageMetrics\EventListener\PageHeaderMetricsRenderer;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * PageHeaderMetricsRendererTest.
 *
 * The listener itself no longer touches the database - it only decides
 * whether to inject a placeholder, so this test covers exactly that
 * decision. Everything the placeholder's data-ajax-url eventually loads is
 * covered by PageMetricsTilesControllerTest instead.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageHeaderMetricsRendererTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    public function testAddsNothingWhenNoPageIsSelected(): void
    {
        $event = $this->dispatch(['id' => '0']);

        self::assertSame('', $event->getHeaderContent());
    }

    public function testInjectsAPlaceholderWithTheAjaxUrlWhenAPageIsSelected(): void
    {
        $event = $this->dispatch(['id' => '2']);

        self::assertStringContainsString('pagemetrics-header-placeholder', $event->getHeaderContent());
        self::assertStringContainsString('data-page-id="2"', $event->getHeaderContent());
        self::assertStringContainsString('data-ajax-url="', $event->getHeaderContent());
        self::assertStringContainsString('ajax/page-metrics/tiles', $event->getHeaderContent());
    }

    public function testAddsNothingWhenTheBackendUsersTsConfigDisablesAccess(): void
    {
        $backendUser = $this->setUpBackendUser(3);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $event = $this->dispatch(['id' => '2']);

        self::assertSame('', $event->getHeaderContent());
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function dispatch(array $queryParams): ModifyPageLayoutContentEvent
    {
        $route = new Route('/module/web/layout', ['packageName' => 'typo3/cms-backend']);
        $request = (new ServerRequest('https://example.com/typo3/module/web/layout'))
            ->withQueryParams($queryParams)
            ->withAttribute('route', $route)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $moduleTemplate = $this->get(ModuleTemplateFactory::class)->create($request);
        $event = new ModifyPageLayoutContentEvent($request, $moduleTemplate);

        ($this->get(PageHeaderMetricsRenderer::class))($event);

        return $event;
    }
}
