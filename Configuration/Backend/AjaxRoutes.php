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

use KonradMichalik\Typo3PageMetrics\Controller\Ajax\{PageMetricsDayController, PageMetricsExportController, PageMetricsPreferencesController, PageMetricsTilesController};

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
    // The panel behind a marker in a sparkline: one day's markers, that
    // day's figures, and the before/after comparison for a marker belonging
    // to the page. Its own route rather than more payload on the tiles
    // response, because it is opened for at most one of the days a period
    // covers and computing it for all of them would cost a query per metric
    // per marker on every page-module load.
    'page_metrics_day' => [
        'path' => '/page-metrics/day',
        'methods' => ['GET'],
        'target' => PageMetricsDayController::class.'::handleRequest',
        'inheritAccessFromModule' => 'web_layout',
    ],
    // Separate from the route above rather than a mode on it: that one is a
    // repeatable GET returning markup, this one changes stored user state,
    // and a read endpoint with a side effect is what lets a prefetch rewrite
    // someone's settings. POST-only for the same reason.
    'page_metrics_preferences' => [
        'path' => '/page-metrics/preferences',
        'methods' => ['POST'],
        'target' => PageMetricsPreferencesController::class.'::handleRequest',
        'inheritAccessFromModule' => 'web_layout',
    ],

    // One file per view of the backend module. Its own route rather than a
    // mode on the module route, and that is not a preference:
    // BackendModuleValidator merges a query parameter named like a moduleData
    // key into the reader's stored module state before dispatch, so a
    // download link carrying `period` and `view` would not describe one
    // download, it would overwrite what that reader sees the next time they
    // open the module.
    //
    // Access is inherited from the module rather than from web_layout like
    // the three routes above, because this one belongs to the module and not
    // to the page header.
    'page_metrics_export' => [
        'path' => '/page-metrics/export',
        'methods' => ['GET'],
        'target' => PageMetricsExportController::class.'::handleRequest',
        'inheritAccessFromModule' => 'web_pagemetrics',
    ],
];
