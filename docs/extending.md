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

`MetricTile` has trailing parameters a listener should leave alone:
`previousPeriodCovered`, `previousValue` and `recordFormattedValue` are filled
by the import pipeline from the coverage window and from the dimension rows,
and a tile built by a listener brought its own numbers and knows neither. They
default to the values that say "no claim made".

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

## Significance

`significance` says how far your metric has to move before the trend widget calls it a movement. It is optional, and a metric that omits it gets a tenth of the previous period's value with no floor.

```php
new MetricDefinition(
    'acme.downloads',
    'Downloads',
    'Completed downloads of this page\'s attachment.',
    'tabler-download',
    MetricUnit::Count,
    50,
    '#0969da',
    MetricPolarity::HigherIsBetter,
    MetricGroup::Audience,
    new MetricSignificance(0.1, 10),
);
```

Both figures have to be cleared, which is the point of having two. `relative` is a share of the previous period's value, so `0.1` means "at least a tenth". `absolute` is a floor in your metric's own unit: views for a count, percentage points for a rate, seconds for a duration. Pass `0` to switch the floor off.

Pick the floor from the volumes your metric actually sees. It exists to stop a page going from two to four leading a list of what rose, so it wants to sit just above the range where a handful of events is indistinguishable from noise. The bundled providers use ten views and three percentage points, deliberately half of what Matomo's Generated Insights plugin uses, because a TYPO3 site is usually smaller than the volumes that figure was calibrated on.

Both `MetricDefinition` and `RateMetricDefinition` take it, appended last in each. Ordering within what survives the thresholds is not yours to set: it is the size of the movement weighted by the traffic behind it, which for a rate is the denominator.
## Metrics that depend on the site

`getMetricDefinitions()` describes the metrics a provider has everywhere. Some
do not exist everywhere: a [goal](page-tiles.md#goal-tiles) exists only because
an operator configured it on one site, with a label and a polarity of that
site's choosing, so no static list can describe it.

A provider that has such metrics implements `SiteAwareMetricProviderInterface`
in addition, and returns them from `getSiteMetricDefinitions(Site $site)`:

```php
public function getSiteMetricDefinitions(Site $site): array
{
    return $this->goalMetrics->definitionsFor($site);
}
```

What comes back is *additional* to `getMetricDefinitions()`, never a
replacement for it, and the two lists are simply concatenated. Everything else
is unchanged: the definitions are ordinary `MetricDefinition`s, the values
behind them are ordinary metrics stored by the usual import, and a provider
that does not implement this interface behaves exactly as it did.

This is a second interface rather than a `Site` parameter on
`getMetricDefinitions()` because `MetricProviderInterface` is `@api`: adding a
parameter there would make PHP reject every third-party provider implementing
it. Implementing this one is opt-in.

For a provider that needs its own daily-import step, its own configurable
retention and to participate in the dashboard widgets, implement
`MetricProviderInterface` instead, see the `Matomo`, `Ga4` and `AccessLog`
providers under `Classes/Provider/` for a full example.

## Records below a page

A detail URL such as `/news/my-article` is not a page slug. It is the page
`/news` plus a route enhancer, and TYPO3's own router is what turns it back
into "page 3, showing news article 42". The import asks the router, so no
extension-specific code and no extra configuration is involved: a site that
routes records already carries everything the attribution needs.

The record's table comes from the enhancer's own aspect, because a persisted
mapper cannot work without naming it:

```yaml
routeEnhancers:
  News:
    type: Extbase
    extension: News
    plugin: Pi1
    routes:
      - { routePath: '/{news_title}', _controller: 'News::detail', _arguments: {news_title: news} }
    aspects:
      news_title:
        type: PersistedAliasMapper
        tableName: tx_news_domain_model_news
        routeFieldName: path_segment
```

`tableName` is the whole contract. Any mapper that declares one takes part,
including a third-party one, and any mapper that does not is not about a
record: a `StaticRangeMapper` on a pagination segment maps a number to a
number, not to a row.

What is deliberately refused:

| Situation | Result |
| --- | --- |
| The route maps two records at once, e.g. `/{category}/{article}` | No record. The URL does not say which one the page is about |
| One argument name claimed by two enhancers for different tables | No record, same reason |
| A mapper that names no table | No record |
| An enhancer whose extension is no longer installed | No record, and the import run continues |

In each case the traffic still counts for the page that renders it, which is
what already happened before any of this existed. A wrong record attribution
would be worse than none: nothing downstream could tell.

The uid stored is always the default-language one. A persisted mapper returns
the translation parent for a translated record, exactly as page resolution
returns the default-language page uid for a translated page, so "the record"
means the same thing in every language and the language stays a dimension of
the stored fact.

Record facts live in `tx_typo3pagemetrics_record_daily`, keyed on
`(date, record_table, record_uid, sys_language_uid, provider, metric, dimension)`.
It is a sibling of `tx_typo3pagemetrics_daily` rather than an extension of it,
so the page module's read path is untouched and carries no empty column. The
same hit is counted in both tables on purpose: the page tile means "this page
and what it renders below it", the record tile means "this record".
