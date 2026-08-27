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

// Scanned unconditionally across every installed package by
// TYPO3\CMS\Dashboard\ServiceProvider::configureWidgetGroups() - that
// scanning only ever runs as part of EXT:dashboard's own bootstrap, so
// (unlike the widget classes themselves, see Configuration/Services.php)
// this file needs no "is EXT:dashboard installed" guard of its own.
return [
    'pageMetrics' => [
        'title' => 'LLL:EXT:page_metrics/Resources/Private/Language/locallang_mod.xlf:widget_group.pageMetrics',
    ],
];
