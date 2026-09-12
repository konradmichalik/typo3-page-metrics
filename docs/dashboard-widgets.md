# Dashboard widgets

`typo3_page_metrics` registers a set of [EXT:dashboard](https://docs.typo3.org/permanent/typo3/cms-dashboard/main/en-us/)
widgets once that extension is installed, so page-level metrics are also
visible site-wide, without opening the page module. Registration is
conditional: on installations without EXT:dashboard, none of this loads (see
`Configuration/Services.php`).

Each widget lists every site that has a configured metric provider, and is
skipped entirely for sites without one.

![The Page Metrics dashboard preset: tiles, chart, two compact charts, trend and most viewed pages](img/dashboard-widgets.png)

| Widget | Identifier | Shows | Fixed period | Configurable |
| --- | --- | --- | --- | --- |
| Page metrics | `pageMetrics` | Each site's homepage tiles: page views, visits, avg. time on page, bounce rate | 7 days | — |
| Page metrics chart | `pageMetricsChart` | A daily chart of one metric per site, with annotation markers | — | Metric, period (7/30/90 days) |
| Page metrics chart (compact) | `pageMetricsChartCompact` | The same chart, sized for compact dashboard layouts | — | Metric, period (7/30/90 days) |
| Most viewed pages | `pageMetricsTopPages` | The highest-traffic pages per site, linked into the page module | 30 days | Page level, include subpages |
| Page metrics trend | `pageMetricsTrend` | Pages that improved or declined most against the previous period | 7/30/90 days | Metric, movement (declines/improvements), period |
| Not found pages | `pageMetricsNotFoundPages` | URLs the daily import could not resolve to a page, with the reason | 30 days | — |
| Page metrics annotations | `pageMetricsAnnotations` | Recent site-wide annotations, such as Matomo release markers | 90 days | — |

Every widget except the site-homepage tiles reads from the same
`SiteMetricsOverviewBuilder`, aggregated across all pages of a site rather
than a single one.

## Data age

Every widget that shows imported numbers (tiles, chart, most viewed pages, trend) prints the day its data is from underneath, and marks it as stale once the import is more than two days behind. See [Data age](page-tiles.md#data-age) for what that date means and why it is normally not today.

Unlike the page module, a dashboard widget covers one site per row, so its date is that site's own provider's and never a compromise between several.

## Movement, not direction

The trend widget ranks by whether a page improved or declined, not by whether its number went up or down. The two only coincide for a metric where higher is better: a rising bounce rate is a decline, and a page whose bounce rate fell shows a green down arrow. Which way round a metric reads is set by its provider, see [Polarity](extending.md#polarity).

A metric with no polarity of its own, such as an average time on page, has no improvement to rank by, so for those the setting falls back to its literal direction meaning.

## Reading the chart

Each chart scales to its own data, so the shape of the curve says nothing about how big the numbers behind it are. The preset makes that concrete by putting two compact charts side by side: without a scale, a few dozen visits and a few thousand draw the same picture.

The chart therefore scales to the range its own data occupies rather than from zero, and names the first and last day underneath. What it shows is the shape of the period; the figures behind it are in the tiles and in the popover.

Those bounds come from the 5th and 95th percentile rather than the plain extremes, so one freak day cannot flatten every ordinary one into a sliver. A day outside that band is drawn clamped to the edge it exceeds, which keeps the chart readable at the cost of understating that one day. The figure itself is in the popover's peak, and in the chart's spoken summary.

Overlaying a second metric drops the area fill and leaves the two outlines, since two filled shapes would just muddy where they overlap. Each series keeps its own scale, so the legend is what tells them apart.

## Chart and trend metric selection

The chart and trend widgets' **Metric** setting both default to each
site's primary metric. Picking one explicitly is only meaningful for a
single-site widget instance: a multi-site widget falls back to each
site's own primary metric for any site whose provider doesn't support the
configured one.

## Most viewed pages: page level and subpages

By default "Most viewed pages" ranks every individual page across the
whole site. Setting **Page level** restricts the ranking to pages at an
exact depth below the site root (level 1 = the site's top-level sections,
level 2 = their children, and so on) instead. Combined with **Include
subpages**, each of those pages' own number becomes its own traffic plus
everything under it rolled up into one total — useful for seeing which
top-level section performs best as a whole, not just its own landing
page. "Include subpages" only has an effect once a page level is set.

## Dashboard preset

A ready-made **Page Metrics** dashboard preset bundles every widget above. It
appears in the dashboard creation wizard once EXT:dashboard is installed, so
a new dashboard covering every site's metrics is one click away.

Below the tiles and the large chart, it places two compact charts (Visits,
Bounce rate), then two trend widgets (Biggest improvements, Biggest
declines), then Most viewed pages and Not found pages, with the annotations
widget last.
The compact chart and trend widgets can each appear more than once with
different settings pre-configured, see
[Configuration/Backend/DashboardPresets.php](../Configuration/Backend/DashboardPresets.php).
