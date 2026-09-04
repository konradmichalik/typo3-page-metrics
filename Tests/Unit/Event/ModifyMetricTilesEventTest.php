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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Event;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricUnit, TrendDirection};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricTile};
use KonradMichalik\Typo3PageMetrics\Event\ModifyMetricTilesEvent;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * ModifyMetricTilesEventTest.
 *
 * Covers the `@api` mutators listeners use, see docs/extending.md.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ModifyMetricTilesEventTest extends TestCase
{
    public function testAddTileAppendsToTheExistingTiles(): void
    {
        $event = $this->event([$this->tile('matomo.pageviews')]);

        $event->addTile($this->tile('custom.rating'));

        self::assertSame(['matomo.pageviews', 'custom.rating'], $this->identifiers($event->getTiles()));
    }

    public function testSetTilesReplacesTheWholeListSoListenersCanReorderOrDrop(): void
    {
        $event = $this->event([$this->tile('matomo.pageviews'), $this->tile('matomo.visits')]);

        $event->setTiles([$this->tile('matomo.visits')]);

        self::assertSame(['matomo.visits'], $this->identifiers($event->getTiles()));
    }

    public function testExposesTheRenderContextUnchanged(): void
    {
        $event = $this->event([]);

        self::assertSame('main', $event->site->getIdentifier());
        self::assertSame(42, $event->pageId);
        self::assertSame(1, $event->languageId);
        self::assertSame('2026-08-25', $event->tileRange->from->format('Y-m-d'));
        self::assertSame('2026-08-18', $event->previousRange->from->format('Y-m-d'));
        self::assertSame('2026-06-01', $event->sparklineRange->from->format('Y-m-d'));
    }

    /**
     * @param list<MetricTile> $tiles
     */
    private function event(array $tiles): ModifyMetricTilesEvent
    {
        return new ModifyMetricTilesEvent(
            $tiles,
            new Site('main', 1, ['base' => 'https://example.com/']),
            42,
            1,
            new DateRange(new DateTimeImmutable('2026-08-25'), new DateTimeImmutable('2026-08-31')),
            new DateRange(new DateTimeImmutable('2026-08-18'), new DateTimeImmutable('2026-08-24')),
            new DateRange(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-08-31')),
        );
    }

    private function tile(string $identifier): MetricTile
    {
        return new MetricTile($identifier, 'Label', 'Description', 'actions-view', '#0969da', MetricUnit::Count, 1, '1', [], null, TrendDirection::Flat, 0, null, '');
    }

    /**
     * @param list<MetricTile> $tiles
     *
     * @return list<string>
     */
    private function identifiers(array $tiles): array
    {
        return array_map(static fn (MetricTile $tile): string => $tile->identifier, $tiles);
    }
}
