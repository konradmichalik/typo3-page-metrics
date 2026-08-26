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

namespace KonradMichalik\PageMetricsDemoTiles\EventListener;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricUnit, TrendDirection};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricTile;
use KonradMichalik\Typo3PageMetrics\Event\ModifyMetricTilesEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * DemoTileEventListener.
 *
 * Proves that a third-party extension can contribute a tile purely by
 * listening to ModifyMetricTilesEvent, without implementing
 * MetricSourceInterface (no fetch/supports/definitions needed) - it just
 * builds its own MetricTile and appends it.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class DemoTileEventListener
{
    #[AsEventListener('page-metrics-demo-tiles/register-demo-tile')]
    public function __invoke(ModifyMetricTilesEvent $event): void
    {
        $event->addTile(new MetricTile(
            'demo.registered_via_event',
            'Registered via event',
            'Contributed by a fixture extension listening to ModifyMetricTilesEvent.',
            'actions-plus',
            '#57606a',
            MetricUnit::Count,
            123,
            '123',
            [],
            null,
            TrendDirection::Flat,
            0,
            null,
            '',
        ));
    }
}
