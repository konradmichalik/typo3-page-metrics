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

// Same "no guard needed" reasoning as DashboardWidgetGroups.php: only ever
// read by EXT:dashboard's own bootstrap. Widget identifiers below are the
// 'identifier' values from the dashboard.widget tags in
// Configuration/Services.php, not those services' own service IDs.
return [
    'pageMetrics' => [
        'title' => 'LLL:EXT:page_metrics/Resources/Private/Language/locallang_mod.xlf:dashboardPreset.title',
        'description' => 'LLL:EXT:page_metrics/Resources/Private/Language/locallang_mod.xlf:dashboardPreset.description',
        'iconIdentifier' => 'tabler-eye-search',
        'defaultWidgets' => [
            'pageMetrics',
            'pageMetricsChart',
            'pageMetricsTopPages',
            'pageMetricsTrend',
            'pageMetricsNotFoundPages',
            'pageMetricsAnnotations',
        ],
        'showInWizard' => true,
    ],
];
