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

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

// Sourced from Tabler Icons (MIT, see Resources/Public/Icons/TABLER_LICENSE.txt).
// tabler-robot, tabler-spider and tabler-world-search are not wired to a
// metric tile yet - they are staged for a future bot/crawler-traffic metric,
// contributed by a third-party listener (e.g. typo3_bot_inspect) via
// ModifyMetricTilesEvent, not by this extension itself.
return [
    'tabler-eye-search' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-eye-search.svg',
    ],
    'tabler-chart-dots' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-chart-dots.svg',
    ],
    'tabler-trending-up' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-trending-up.svg',
    ],
    'tabler-trending-down' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-trending-down.svg',
    ],
    'tabler-minus' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-minus.svg',
    ],
    'tabler-info-circle' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-info-circle.svg',
    ],
    'tabler-clock' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-clock.svg',
    ],
    'tabler-door-exit' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-door-exit.svg',
    ],
    'tabler-dots-vertical' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-dots-vertical.svg',
    ],
    'tabler-robot' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-robot.svg',
    ],
    'tabler-spider' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-spider.svg',
    ],
    'tabler-world-search' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-world-search.svg',
    ],
    'tabler-list' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-list.svg',
    ],
    'tabler-note' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-note.svg',
    ],
];
