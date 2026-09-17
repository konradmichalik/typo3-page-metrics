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
// Every icon a bundled provider names in a MetricDefinition has to be
// registered here, including the bot buckets that only ever appear inside
// the machine-traffic summary today: an icon this list is missing renders as
// an empty box the moment anything gives that bucket a tile of its own.
// MetricDefinitionIconTest holds that seam.
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
    'tabler-rocket' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-rocket.svg',
    ],
    'tabler-speakerphone' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-speakerphone.svg',
    ],
    'tabler-pencil' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-pencil.svg',
    ],
    'tabler-layout-dashboard' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-layout-dashboard.svg',
    ],
    'tabler-alert-triangle' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-alert-triangle.svg',
    ],
    'tabler-external-link' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-external-link.svg',
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
    'tabler-message-chatbot' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-message-chatbot.svg',
    ],
    'tabler-share' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-share.svg',
    ],
    'tabler-tool' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-tool.svg',
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
    'tabler-sitemap' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-sitemap.svg',
    ],
    'tabler-target' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-target.svg',
    ],
    'tabler-coin' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-coin.svg',
    ],
    'tabler-chevron-up' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_page_metrics/Resources/Public/Icons/tabler-chevron-up.svg',
    ],
];
