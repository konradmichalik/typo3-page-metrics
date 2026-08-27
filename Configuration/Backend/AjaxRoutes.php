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

use KonradMichalik\Typo3PageMetrics\Controller\Ajax\PageMetricsTilesController;

// Registered so PageHeaderMetricsRenderer can inject a lightweight placeholder
// into the page module's own synchronous render and defer all data-fetching
// (site resolution, tile computation, several database queries) to a separate
// request fired by client-side JS after the page has already loaded. This
// keeps the extension's work off the page module's own critical rendering
// path entirely.
return [
    'page_metrics_tiles' => [
        'path' => '/page-metrics/tiles',
        'methods' => ['GET'],
        'target' => PageMetricsTilesController::class.'::handleRequest',
        'inheritAccessFromModule' => 'web_layout',
    ],
];
