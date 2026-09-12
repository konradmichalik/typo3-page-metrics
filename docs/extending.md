# Registering custom tiles

Any extension can contribute an additional tile to the page module header
by listening to `ModifyMetricTilesEvent`, without implementing the full
`MetricProviderInterface` contract (`getIdentifier()`/`supports()`/`fetch()`/
definitions). This is the right approach when the tile's data does not come
from the daily-import pipeline at all, e.g. a value computed on the fly or
read from another extension's own storage.

```php
<?php

declare(strict_types=1);

namespace Vendor\MyExtension\EventListener;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricPolarity, MetricUnit, TrendDirection};
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
            description: 'Shown on every page, regardless of the configured metric provider.',
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
            polarity: MetricPolarity::HigherIsBetter,
        ));
    }
}
```

The event is dispatched once per page-header render, after the built-in
`MetricProviderInterface` providers (Matomo, GA4) have already contributed
their own tiles via `$event->getTiles()`. It also exposes `$event->site`,
`$event->pageId`, `$event->languageId` and the `DateRange`s the tiles were
built for (`$event->tileRange`, `$event->previousRange`,
`$event->sparklineRange`), so a listener can tailor its tile to the same
period the built-in tiles use.

## Polarity

`polarity` says whether a rise in your metric is an improvement, and nothing else can: the platform never interprets what a metric means. It decides the colour of the trend badge and the word a screen reader reads out for it, so a metric where a rise is the bad outcome needs `MetricPolarity::LowerIsBetter` to avoid showing a worsening number in green and announcing it as improved.

| Value | Use for |
| --- | --- |
| `HigherIsBetter` (default) | page views, visits, conversions |
| `LowerIsBetter` | bounce rate, error counts |
| `Neutral` | anything whose direction is not a judgement, such as an average time on page |

The arrow icon always follows the actual direction, so `LowerIsBetter` shows a down arrow in green rather than inverting the arrow.

`MetricDefinition` takes this parameter. `RateMetricDefinition` does not: a rate cannot be summed, so there is nothing for a machine-side rate to fold into, and a parameter nothing reads is a promise nothing keeps. Note that `MetricDefinition::$maxDimensions` was removed in the same change: it was never read. A positional call that passed it now fails loudly instead of silently landing in `polarity`.

## Audience and machine metrics

`group` says who your metric counts. `MetricGroup::Audience`, the default, means people, and those metrics get a tile each in the page module and in the dashboard's tiles widget. `MetricGroup::Machine` means everything else: crawlers, AI agents, link-preview fetchers, monitoring.

Machine metrics do not get a tile each. All of a provider's machine metrics fold into one summary tile carrying their total, which lists the parts when it is opened. That is not a display preference: a row of equally weighted tiles asserts that its numbers answer the same question, and "how many people read this" and "how often did a crawler fetch it" do not.

The fold is per provider, so yours summarises its own metrics and not somebody else's. It is placed where your first machine metric's `sortOrder` would have put it, so the order you declared is the order that shows.

Nothing about storage or import changes. Each machine metric keeps its own identifier and its own daily rows; only the display is folded.

Only `MetricDefinition` takes it, appended after `polarity`. Unlike `polarity`, which took a slot `maxDimensions` had freed, this one is genuinely new, so a positional call written against the older constructor keeps meaning exactly what it meant. `RateMetricDefinition` has no such parameter: a rate cannot be summed, so there is nothing for a machine-side rate to fold into.

For a provider that needs its own daily-import step, its own configurable
retention and to participate in the dashboard widgets, implement
`MetricProviderInterface` instead, see the `Matomo`, `Ga4` and `AccessLog`
providers under `Classes/Provider/` for a full example.
