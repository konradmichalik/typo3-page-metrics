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

use KonradMichalik\Typo3PageMetrics\Controller\PageMetricsModuleController;

return [
    'web_pagemetrics' => [
        // Under `web`, not `tools`. TYPO3 14 renamed the module groups and
        // keeps `web` as an alias for `content`, while the new v14 groups
        // (`content_status` and the rest) do not exist in 13.4 at all, so
        // this is the one parent that names the same place on both.
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'user',
        'iconIdentifier' => 'tabler-chart-dots',
        'path' => '/module/web/pagemetrics',
        // The array form, not the bare file path. Given a path, both versions
        // append `:mlang_tabs_tab` and friends themselves, which only works
        // for a file whose keys are spelled exactly that; this one namespaces
        // its module keys like every other key it holds.
        'labels' => [
            'title' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:module.mlang_tabs_tab',
            'description' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:module.mlang_labels_tabdescr',
            'shortDescription' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:module.mlang_labels_tablabel',
        ],
        // The page tree, so the selected page is module state rather than a
        // field inside the module: switching pages keeps the period and the
        // view, which is what makes comparing two pages bearable.
        'navigationComponent' => '@typo3/backend/tree/page-tree-element',
        // One route with a view switch in the docheader rather than
        // submodules: `showSubmoduleOverview` is TYPO3 14 only, the group
        // structure differs between the versions, and five menu entries for
        // one extension is pushy.
        'routes' => [
            '_default' => [
                'target' => PageMetricsModuleController::class.'::handleRequest',
            ],
        ],
        // BackendModuleValidator merges a query parameter named like any of
        // these into the stored data and persists it before the request
        // reaches the controller, so every docheader control and every
        // in-view picker is a plain link and nothing writes state by hand.
        'moduleData' => [
            'period' => 30,
            'view' => 'page',
            'includeSubpages' => false,
            'compareBaseline' => 'previous',
            // Which metric you always look at is a preference and belongs
            // here; which page of the table you stopped on is a position
            // inside one reading and stays in the URL.
            'sort' => '',
            'direction' => 'desc',
        ],
    ],
];
