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

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;

$moduleIcon = (new Typo3Version())->getMajorVersion() >= 14
    ? 'EXT:page_metrics/Resources/Public/Icons/module-icon.svg'
    : 'EXT:page_metrics/Resources/Public/Icons/Extension.svg';

return [
    'tx-pagemetrics-module' => [
        'provider' => SvgIconProvider::class,
        'source' => $moduleIcon,
    ],
];
