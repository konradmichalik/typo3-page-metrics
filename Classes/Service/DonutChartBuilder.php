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

namespace KonradMichalik\Typo3PageMetrics\Service;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DonutChart;

use function array_column;
use function array_sum;
use function count;
use function implode;
use function number_format;
use function rtrim;
use function sprintf;

/**
 * DonutChartBuilder.
 *
 * A tile's breakdown as a CSS conic-gradient() donut instead of (or beside)
 * the plain "Made up of" list, for a widget with more room to spend on one
 * metric than the popover has. Drawn in PHP rather than in Fluid or in JS,
 * because a slice needs to know where the one before it ended, and that is a
 * running total, not a template expression.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class DonutChartBuilder
{
    /**
     * @var list<string>
     */
    private const COLORS = [
        'var(--pm-donut-color-0)',
        'var(--pm-donut-color-1)',
        'var(--pm-donut-color-2)',
        'var(--pm-donut-color-3)',
        'var(--pm-donut-color-4)',
        'var(--pm-donut-color-5)',
        'var(--pm-donut-color-6)',
    ];

    /**
     * Null wherever a slice cannot be drawn honestly: a part with no $value
     * (a listener's own breakdown is not required to carry one, see
     * MetricTile::$breakdown) would either be skipped, understating the
     * whole "Made up of" claims, or sized as zero, which is a different
     * claim than "unknown". Neither is the donut's to make, so it draws
     * nothing rather than draw the wrong thing.
     *
     * @param list<array{label: string, formattedValue: string, value?: int, hint?: string}> $breakdown
     */
    public function build(array $breakdown): ?DonutChart
    {
        $values = array_column($breakdown, 'value');

        if ([] === $breakdown || count($values) !== count($breakdown)) {
            return null;
        }

        $total = array_sum($values);

        if ($total <= 0) {
            return null;
        }

        $stops = [];
        $legend = [];
        $cursor = 0.0;

        foreach ($breakdown as $index => $part) {
            // Never actually missing here: the count check above already
            // guarantees every part carries one. Read defensively anyway,
            // since $breakdown's own shape marks the key optional.
            $value = $part['value'] ?? 0;

            if ($value <= 0) {
                continue;
            }

            $color = self::COLORS[$index % count(self::COLORS)];
            // Accumulated from the precise share, not from the rounded stop
            // strings below - rounding each slice on its own would leave
            // gaps or overlaps between it and the next one.
            $share = $value / $total * 100;
            $stops[] = sprintf('%s %s%% %s%%', $color, $this->trimmed($cursor), $this->trimmed($cursor + $share));
            $cursor += $share;

            $legendPart = [
                'label' => $part['label'],
                'formattedValue' => $part['formattedValue'],
                'color' => $color,
            ];

            if (isset($part['hint'])) {
                $legendPart['hint'] = $part['hint'];
            }

            $legend[] = $legendPart;
        }

        if ([] === $stops) {
            return null;
        }

        return new DonutChart(sprintf('conic-gradient(%s)', implode(', ', $stops)), $legend);
    }

    private function trimmed(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
