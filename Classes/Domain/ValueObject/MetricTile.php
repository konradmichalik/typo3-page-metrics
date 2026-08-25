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

namespace KonradMichalik\Typo3PageMetrics\Domain\ValueObject;

final readonly class MetricTile
{
    /**
     * @param array<string, int> $sparkline Y-m-d => value, fixed 30-day window regardless of the tile's own period
     */
    public function __construct(
        public string $identifier,
        public string $label,
        public string $icon,
        public string $unit,
        public int $value,
        public array $sparkline,
    ) {}
}
