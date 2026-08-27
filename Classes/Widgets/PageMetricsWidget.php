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

namespace KonradMichalik\Typo3PageMetrics\Widgets;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\Annotation;
use KonradMichalik\Typo3PageMetrics\Service\PageMetricsViewDataBuilder;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Dashboard\Widgets\{AdditionalCssInterface, JavaScriptInterface, RequestAwareWidgetInterface, WidgetConfigurationInterface, WidgetInterface};

/**
 * PageMetricsWidget.
 *
 * A dashboard-level presentation of the same PageMetricsViewData the
 * PageHeader tiles and the tiles AJAX controller already render - one row
 * per site, showing that site's homepage tiles for a fixed, short period.
 * Only registered as a dashboard widget when EXT:dashboard is installed,
 * see Configuration/Services.php.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageMetricsWidget implements WidgetInterface, RequestAwareWidgetInterface, AdditionalCssInterface, JavaScriptInterface
{
    private const PERIOD_DAYS = 7;

    private ServerRequestInterface $request;

    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly PageMetricsViewDataBuilder $viewDataBuilder,
        private readonly SiteFinder $siteFinder,
        private readonly BackendViewFactory $backendViewFactory,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function renderWidgetContent(): string
    {
        // 'typo3/cms-dashboard' as a second package lets the template use
        // <f:layout name="Widget/Widget" /> from EXT:dashboard itself, the
        // same layout every core widget renders through.
        $view = $this->backendViewFactory->create($this->request, ['konradmichalik/typo3-page-metrics', 'typo3/cms-dashboard']);
        $view->assignMultiple([
            'sites' => $this->collectSiteTiles(),
            'configuration' => $this->configuration,
        ]);

        return $view->render('Widget/PageMetrics');
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function getCssFiles(): array
    {
        return ['EXT:typo3_page_metrics/Resources/Public/Css/page-metrics.css'];
    }

    /**
     * @return list<JavaScriptModuleInstruction>
     */
    public function getJavaScriptModuleInstructions(): array
    {
        return [JavaScriptModuleInstruction::create('@konradmichalik/page-metrics/sparkline.js')];
    }

    /**
     * @return list<array{site: Site, tiles: list<\KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricTile>, annotations: list<array{date: string, text: string}>}>
     */
    private function collectSiteTiles(): array
    {
        $result = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            $viewData = $this->viewDataBuilder->build($site->getRootPageId(), 0, self::PERIOD_DAYS);

            if (!$viewData->hasPage || [] === $viewData->tiles) {
                continue;
            }

            $result[] = [
                'site' => $site,
                'tiles' => $viewData->tiles,
                'annotations' => array_map(
                    static fn (Annotation $annotation): array => ['date' => $annotation->date->format('Y-m-d'), 'text' => $annotation->text],
                    $viewData->annotations,
                ),
            ];
        }

        return $result;
    }
}
