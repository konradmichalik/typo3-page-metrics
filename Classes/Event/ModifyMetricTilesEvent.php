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

namespace KonradMichalik\Typo3PageMetrics\Event;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricTile};
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * ModifyMetricTilesEvent.
 *
 * Dispatched once per page-header render after the built-in MetricSourceInterface
 * sources have contributed their tiles. Any extension can listen for this event
 * and call addTile() to contribute one more tile without implementing the full
 * MetricSourceInterface contract (fetch/supports/definitions) - useful when the
 * data behind a tile does not need the daily-import pipeline at all.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ModifyMetricTilesEvent
{
    /**
     * @param list<MetricTile> $tiles
     */
    public function __construct(
        private array $tiles,
        public readonly Site $site,
        public readonly int $pageId,
        public readonly int $languageId,
        public readonly DateRange $tileRange,
        public readonly DateRange $previousRange,
        public readonly DateRange $sparklineRange,
    ) {}

    /**
     * @return list<MetricTile>
     */
    public function getTiles(): array
    {
        return $this->tiles;
    }

    /**
     * @param list<MetricTile> $tiles
     */
    public function setTiles(array $tiles): void
    {
        $this->tiles = $tiles;
    }

    public function addTile(MetricTile $tile): void
    {
        $this->tiles[] = $tile;
    }
}
