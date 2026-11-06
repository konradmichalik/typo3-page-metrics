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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Widgets;

use KonradMichalik\Typo3PageMetrics\Widgets\WidgetPeriod;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function file_get_contents;
use function preg_match_all;
use function str_replace;

/**
 * WidgetPeriodTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class WidgetPeriodTest extends TestCase
{
    private const LL_PREFIX = 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:';

    public function testKeepsAnOfferedPeriod(): void
    {
        self::assertSame(90, WidgetPeriod::resolve(90, 7));
    }

    public function testFallsBackToTheDefaultForAPeriodNotOffered(): void
    {
        self::assertSame(7, WidgetPeriod::resolve(14, 7));
    }

    public function testSettingOffersSevenThirtyAndNinetyDaysWithTheGivenDefault(): void
    {
        $definition = WidgetPeriod::settingDefinition(30, 'widget.chart.setting.period.description');

        self::assertSame('period', $definition->key);
        self::assertSame('int', $definition->type);
        self::assertSame(30, $definition->default);
        self::assertSame([7, 30, 90], array_keys($definition->enum));
    }

    public function testEveryLabelTheSettingAndTheTitleUsePointsAtAnExistingTranslation(): void
    {
        $definition = WidgetPeriod::settingDefinition(7, 'widget.setting.period.description');
        $keys = [
            $definition->label,
            (string) $definition->description,
            ...$definition->enum,
            WidgetPeriod::labelKey(30),
        ];

        $existing = $this->translationIds();

        foreach ($keys as $key) {
            self::assertIsString($key);
            self::assertContains(str_replace(self::LL_PREFIX, '', $key), $existing, $key);
        }
    }

    /**
     * @return list<string>
     */
    private function translationIds(): array
    {
        preg_match_all('/<trans-unit id="([^"]+)"/', (string) file_get_contents(__DIR__.'/../../../Resources/Private/Language/locallang_mod.xlf'), $matches);

        return $matches[1];
    }
}
