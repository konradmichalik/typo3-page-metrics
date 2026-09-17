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

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\Insight;
use KonradMichalik\Typo3PageMetrics\Service\InsightBuilder;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Site\{Entity\Site, SiteFinder};
use TYPO3\CMS\Dashboard\Widgets\{AdditionalCssInterface, RequestAwareWidgetInterface, WidgetConfigurationInterface, WidgetInterface};

use function array_values;
use function count;

/**
 * InsightWidget.
 *
 * The one widget that states rather than displays: at most four sentences
 * about what moved in the last thirty days, and nothing at all when nothing
 * did (see InsightBuilder for which four and in what order).
 *
 * Not built on AbstractSiteMetricsWidget, and not settings-aware, both on
 * purpose:
 *
 * - that base class renders one block per site out of a whole
 *   SiteMetricsOverview, and this widget's cards are pooled across sites
 *   into a single ranked list of four, so there is no per-site block to
 *   share and none of the overview to reuse;
 * - a settings dialog would need WidgetRendererInterface, which is a TYPO3
 *   14 addition (see Configuration/Services.php). A widget that leads the
 *   dashboard preset should exist on both supported versions, and there is
 *   nothing here worth configuring: the period is fixed and the thresholds
 *   belong to the metrics.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class InsightWidget implements WidgetInterface, RequestAwareWidgetInterface, AdditionalCssInterface
{
    /**
     * Fixed, and the same thirty days the other site-wide widgets report:
     * a card states its own period in words, and two widgets side by side
     * claiming different months under the same heading is exactly the
     * confusion a sentence is supposed to end.
     */
    private const PERIOD_DAYS = 30;

    private ServerRequestInterface $request;

    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly InsightBuilder $insightBuilder,
        private readonly SiteFinder $siteFinder,
        private readonly BackendViewFactory $backendViewFactory,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function renderWidgetContent(): string
    {
        $sites = $this->siteFinder->getAllSites();
        $insights = $this->insightBuilder->build($sites, self::PERIOD_DAYS);

        $view = $this->backendViewFactory->create($this->request, ['konradmichalik/typo3-page-metrics', 'typo3/cms-dashboard']);
        $contributingSites = $this->contributingSites($sites, $insights);

        $view->assignMultiple([
            'insights' => $insights,
            // Which of the two empty states applies. "Nothing moved" is a
            // finding about a configured site; on an installation that has
            // configured nothing it would be a claim about data nobody
            // collected, which is the one over-claim this widget of all
            // widgets cannot make.
            'hasProvider' => $this->insightBuilder->hasSpeakableSite($sites),
            // Counted over the sites that actually produced a card, not over
            // every site installed. A second site with no provider
            // configured contributes nothing and says nothing, so naming the
            // one site on each of four cards would be exactly the noise this
            // is meant to avoid.
            'showSite' => count($contributingSites) > 1,
            'configuration' => $this->configuration,
        ]);

        return $view->render('Widget/Insight');
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
     * The sites that actually produced a card, in the order their first card
     * appears. A site the widget said nothing about is not among them, which
     * is what makes counting them the right test for whether a card has to
     * name its site at all.
     *
     * @param array<string, Site> $sites
     * @param list<Insight>       $insights
     *
     * @return list<Site>
     */
    private function contributingSites(array $sites, array $insights): array
    {
        $contributing = [];

        foreach ($insights as $insight) {
            $site = $sites[$insight->siteIdentifier] ?? null;

            if (null !== $site) {
                $contributing[$insight->siteIdentifier] = $site;
            }
        }

        return array_values($contributing);
    }
}
