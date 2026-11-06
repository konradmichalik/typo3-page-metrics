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

namespace KonradMichalik\Typo3PageMetrics\Widgets;

use TYPO3\CMS\Core\Settings\SettingDefinition;

use function array_combine;
use function array_map;
use function in_array;

/**
 * WidgetPeriod.
 *
 * The period setting every configurable dashboard widget offers, so the
 * options, the fallback and the labels cannot drift apart between widgets.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class WidgetPeriod
{
    /**
     * @var list<int>
     */
    public const OPTIONS = [7, 30, 90];

    private const LL = 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:';

    public static function resolve(int $period, int $default): int
    {
        return in_array($period, self::OPTIONS, true) ? $period : $default;
    }

    public static function labelKey(int $period): string
    {
        return self::LL.'widget.trend.setting.period.'.$period;
    }

    /**
     * @param string $descriptionId what the period means for this widget, as a locallang_mod.xlf id
     */
    public static function settingDefinition(int $default, string $descriptionId): SettingDefinition
    {
        return new SettingDefinition(
            'period',
            'int',
            $default,
            self::LL.'widget.setting.period.label',
            self::LL.$descriptionId,
            false,
            array_combine(self::OPTIONS, array_map(self::labelKey(...), self::OPTIONS)),
        );
    }
}
