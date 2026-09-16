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

namespace KonradMichalik\Typo3PageMetrics\Domain\ValueObject;

/**
 * TileStat.
 *
 * One of the qualifying figures beside a tile's headline: the last day, the
 * peak, the share that arrived through a redirect, what the typical page on
 * this site did. Already translated and already formatted in the metric's
 * own unit, because the two surfaces that show these are a JavaScript
 * popover and a Fluid template and neither is the place to decide what a
 * duration looks like.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TileStat
{
    /**
     * @param string      $label the figure's name, e.g. "Peak"
     * @param string      $hint  what it means, e.g. "The highest value a single day
     *                           reached". Empty where the label says it on its own
     * @param string|null $value the formatted figure, null where the period holds no
     *                           day this one could be read from. Null rather than a
     *                           formatted zero: no data and a measured zero are
     *                           different facts about the page
     */
    public function __construct(
        public string $label,
        public string $hint,
        public ?string $value,
    ) {}
}
