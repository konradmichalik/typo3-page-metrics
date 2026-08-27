# Registering custom tiles

Any extension can contribute an additional tile to the page module header
by listening to `ModifyMetricTilesEvent`, without implementing the full
`MetricSourceInterface` contract (`getIdentifier()`/`supports()`/`fetch()`/
definitions). This is the right approach when the tile's data does not come
from the daily-import pipeline at all, e.g. a value computed on the fly or
read from another extension's own storage.

```php
<?php

declare(strict_types=1);

namespace Vendor\MyExtension\EventListener;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricUnit, TrendDirection};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricTile;
use KonradMichalik\Typo3PageMetrics\Event\ModifyMetricTilesEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

final class AddCustomTileEventListener
{
    #[AsEventListener('my-extension/add-custom-tile')]
    public function __invoke(ModifyMetricTilesEvent $event): void
    {
        $event->addTile(new MetricTile(
            identifier: 'my_extension.custom_metric',
            label: 'Custom metric',
            description: 'Shown on every page, regardless of the configured metric source.',
            icon: 'actions-plus',
            color: '#57606a',
            unit: MetricUnit::Count,
            value: 42,
            formattedValue: '42',
            sparkline: [],
            deltaPercent: null,
            trend: TrendDirection::Flat,
            redirectSharePercent: 0,
            externalLink: null,
            previousFormattedValue: '',
        ));
    }
}
```

The event is dispatched once per page-header render, after the built-in
`MetricSourceInterface` sources (Matomo, GA4) have already contributed their
own tiles via `$event->getTiles()`. It also exposes `$event->site`,
`$event->pageId`, `$event->languageId` and the `DateRange`s the tiles were
built for (`$event->tileRange`, `$event->previousRange`,
`$event->sparklineRange`), so a listener can tailor its tile to the same
period the built-in tiles use.

For a source that needs its own daily-import step, its own configurable
retention and to participate in the dashboard widgets, implement
`MetricSourceInterface` instead, see the `Matomo` and `Ga4` sources under
`Classes/Source/` for a full example.
