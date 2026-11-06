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

// Same "no guard needed" reasoning as DashboardWidgetGroups.php: only ever
// read by EXT:dashboard's own bootstrap. Widget identifiers below are the
// 'identifier' values from the dashboard.widget tags in
// Configuration/Services.php, not those services' own service IDs.
return [
    'pageMetrics' => [
        'title' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:dashboardPreset.title',
        'description' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:dashboardPreset.description',
        'iconIdentifier' => 'tabler-eye-search',
        'defaultWidgets' => [
            // First, because a sentence saying what changed is what a reader
            // can act on before they have read a single chart.
            'pageMetricsInsight',
            'pageMetrics',
            'pageMetricsChart',
            [
                'identifier' => 'pageMetricsChartCompact',
                'settings' => ['metric' => 'matomo.visits'],
            ],
            [
                'identifier' => 'pageMetricsChartCompact',
                'settings' => ['metric' => 'matomo.bounce_rate'],
            ],
            // Bot traffic, because it is the metric with parts to draw as a
            // donut. A site without an access log falls back to its primary metric.
            [
                'identifier' => 'pageMetricsBreakdown',
                'settings' => ['metric' => 'machine.summary.accesslog'],
            ],
            [
                'identifier' => 'pageMetricsTrend',
                'settings' => ['sentiment' => 'positive'],
            ],
            [
                'identifier' => 'pageMetricsTrend',
                'settings' => ['sentiment' => 'negative'],
            ],
            'pageMetricsTopPages',
            'pageMetricsNotFoundPages',
            'pageMetricsAnnotations',
        ],
        'showInWizard' => true,
    ],
];
