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

namespace KonradMichalik\Typo3PageMetrics\Domain\Enum;

/**
 * ModuleView.
 *
 * Which view of the backend module is active, kept in `moduleData` and
 * switched in the docheader rather than through submodules: the module menu
 * groups differ between 13.4 and 14, `showSubmoduleOverview` is 14 only, and
 * one extension claiming five menu entries is pushy.
 *
 * Only the cases that have a view behind them are listed, so a half-built
 * view cannot reach the docheader by accident. While there is one, the
 * docheader shows no switch at all: a menu offering a single entry is not a
 * choice. The value is still module state, because it is the contract the
 * next view plugs into.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
enum ModuleView: string
{
    case Page = 'page';

    /**
     * What the module opens on for a reader who has never chosen: the page
     * view, because the page tree beside it has just been used to pick a
     * page and any other default would ignore that.
     */
    public static function default(): self
    {
        return self::Page;
    }

    public function labelKey(): string
    {
        return 'module.view.'.$this->value;
    }
}
