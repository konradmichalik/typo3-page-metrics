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

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\SiteMetricsOverview;
use KonradMichalik\Typo3PageMetrics\Service\SiteMetricsOverviewBuilder;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Dashboard\Widgets\{AdditionalCssInterface, RequestAwareWidgetInterface, WidgetConfigurationInterface, WidgetInterface};

/**
 * AbstractSiteMetricsWidget.
 *
 * Shared "one row per site, built from SiteMetricsOverviewBuilder" shape for
 * the four site-wide dashboard widgets (chart, top pages, not-found pages,
 * annotations) - each subclass only names its own template and period.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
abstract class AbstractSiteMetricsWidget implements WidgetInterface, RequestAwareWidgetInterface, AdditionalCssInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly SiteMetricsOverviewBuilder $overviewBuilder,
        private readonly SiteFinder $siteFinder,
        private readonly BackendViewFactory $backendViewFactory,
    ) {}

    final public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    final public function renderWidgetContent(): string
    {
        // 'typo3/cms-dashboard' as a second package lets the template use
        // <f:layout name="Widget/Widget" /> from EXT:dashboard itself, the
        // same layout every core widget renders through - that's what
        // supplies the standard .widget-content-main padding/structure
        // instead of each widget reinventing it.
        $view = $this->backendViewFactory->create($this->request, ['konradmichalik/typo3-page-metrics', 'typo3/cms-dashboard']);
        $view->assignMultiple([...$this->buildViewVariables($this->collectOverviews()), 'configuration' => $this->configuration]);

        return $view->render($this->templateName());
    }

    /**
     * @return array<string, mixed>
     */
    final public function getOptions(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    final public function getCssFiles(): array
    {
        return ['EXT:typo3_page_metrics/Resources/Public/Css/page-metrics.css'];
    }

    abstract protected function templateName(): string;

    abstract protected function periodDays(): int;

    /**
     * @param list<SiteMetricsOverview> $overviews
     *
     * @return array<string, mixed>
     */
    abstract protected function buildViewVariables(array $overviews): array;

    /**
     * @return list<SiteMetricsOverview>
     */
    private function collectOverviews(): array
    {
        $result = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            $overview = $this->overviewBuilder->build($site, 0, $this->periodDays());

            if (!$overview->hasProvider) {
                continue;
            }

            $result[] = $overview;
        }

        return $result;
    }
}
