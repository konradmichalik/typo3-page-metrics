# Page module tiles

Opening **Web → Page** and selecting a page injects a lightweight placeholder
below the page title (via TYPO3 core's `ModifyPageLayoutContentEvent`, the
same extension point EXT:sys_note uses). Client-side JS then fetches the
actual tiles from `PageMetricsTilesController` right after the page has
loaded, so this never sits on the page module's own critical rendering path.

![Page metrics tiles below the page title, with the period switch above them](img/page-tiles.png)

## Period switch

A `7 / 30 / 90 days` switch above the tiles controls the period every tile
and its sparkline cover. The active period is carried in the page module URL
(`tx_typo3pagemetrics_period`), so it survives a reload or a bookmark.

## Including subpages

When the selected page has subpages, an options menu (`⋮`) appears next to
the period switch with a single **Include subpages** toggle. Turning it on
aggregates the tiles across the whole subtree instead of just the current
page, useful for a section landing page where the individual child pages
carry the actual traffic. The choice is carried in the URL
(`tx_typo3pagemetrics_includeSubpages`) the same way the period is, and the
trigger button gets a small badge while the filter is active.

## Sparkline popover

Clicking (or focusing, via keyboard) a tile opens a popover with a
day-by-day sparkline for that metric over the selected period. Dashed
vertical markers on the sparkline mark any [annotation](#annotations) that
falls in the shown range, and an annotation covering several days is drawn
as a strip along the bottom of the chart spanning them, rather than as a
tick on the day it started. Along the bottom rather than across the plot:
the strip says which days without ever sitting between the reader and the
curve. Both take the chart's own colour here, since this popover carries no
legend for a category colour to be looked up in, and the kind of event is
named in words in the tooltip anyway. The
[dashboard chart](dashboard-widgets.md) keeps the category colours, because
it does have a legend. Hovering or focusing a marked date shows the kind of
event and its text in the tooltip alongside that day's value.

![Sparkline popover for the Visits tile, showing last day/previous day/peak/average and a dated tooltip](img/page-tiles-sparkline-popover.png)

The sparkline carries no scale of its own. It is a filled silhouette of the period with its own outline, and the numbers that give it magnitude are the row of figures directly above it. The first and last day sit above it too, so the chart closes the popover at its own bottom edge.

Above the chart the popover shows the period's figures as an icon and a number each: the most recent day, the day before it, the peak, the average, and the share that arrived through a redirect. Hovering one shows its name and what it counts, in a tooltip of the extension's own rather than the browser's title attribute, which waits about a second and runs both together into one line. Keyboard focus shows the same tooltip, and a tap toggles it, since a touch device has no hover at all. The written name and explanation are in the markup either way, so a screen reader reads "Peak. The highest value a single day reached. 162" rather than a bare number. They are simply not drawn: five two-word labels wrapped the row onto three uneven lines and pushed one value out of alignment with the rest.

That tooltip is the house style for every explanation in this extension, not just the popover's figures: the trend badge on a tile names both periods and their values, the date mark names the day the numbers are from, and the fold-away control says which way it will fold. All of them are written the same way in the markup, which is what keeps them behaving the same way.

The browser's own `title` attribute is not used for any of them. It waits about a second before appearing, cannot be styled to match the backend, and runs a heading and its explanation together into a single line.

The axis does not start at zero. It uses the same percentile band as the [dashboard chart](dashboard-widgets.md#reading-the-chart), for the same reason: a page holding steady between 446 and 457 views a day puts its whole variation into the top two percent of a zero-based chart and draws as a flat line. The magnitude is already spelled out as numbers on the row above; what the chart is there for is the shape.

The lowest value of the period is drawn clear of the bottom edge rather than on it, so a page whose quietest day still had traffic does not read as having dropped to nothing.

## Is that good?

A number on its own does not say whether a page is doing well. "31% bounce rate" only means something once you know whether the rest of the site sits at 20% or at 60%, so the popover carries a "Typical page" figure alongside the others: the median across every page of the same site over the same period. Hovering it says how many pages that median was taken over.

For a count metric the same hover also says roughly where this page sits in that field, as a band rather than a percentile. It is a sentence rather than a chip of its own because it is the one thing here you look up once and not on every scan of the row. On a site with eleven pages the difference between the 63rd and the 72nd percentile is one page changing its mind, and a number that moves that easily invites a reading it cannot support. The five bands are top fifth, above the typical page, the typical page itself, below it, and bottom fifth.

A rate metric gets the median but no band, because "in the top fifth" would mean the best fifth for page views and the worst fifth for a bounce rate, and a phrase that flips its meaning from tile to tile is worse than no phrase.

Pages with no data are left out rather than counted as zero, since a zero would drag the typical page towards a value no page actually had. For a rate the same applies to a page with nothing in its denominator: it has no rate at all. The comparison needs at least two pages with data to appear, and at least five before it names a band, because a fifth of four pages is not a fifth.

While the tiles aggregate a subtree the comparison disappears: the number is then a section total, and the field it would be measured against is made of individual pages.

The dashboard's own tiles widget does not show the comparison at all. It renders synchronously, and the field costs one aggregate over every page of the site per metric; the page module can afford that because its tiles arrive through an AJAX route, and a widget cannot.

## Bot traffic

A page's tiles answer how it performs with people. Crawler traffic is a different question, and when the access-log provider is configured it used to arrive as five more tiles of the same size and weight beside the two an editor came for, pushing the answer off the end of the row.

Those five now fold into one **Bot traffic** tile carrying their total, in grey rather than a colour of its own, and the popover lists what it is made of. Every bucket is listed, including the ones at zero: a bucket that is absent and a bucket reading zero answer different questions.

Nothing about the import changes. Each bucket is still fetched, resolved and stored under its own identifier exactly as before, and the summary is assembled when the tiles are built. See [bot traffic metrics](providers/accesslog.md#bot-traffic-metrics) for what each bucket counts.

A provider decides which side of this its metrics fall on, see [Audience and machine metrics](extending.md#audience-and-machine-metrics).

## Goal tiles

Traffic is not business value. A page view count cannot say whether a page did
what it exists to do, and a goal is the site's own answer to that: a Matomo
goal or a GA4 key event, configured per site because only the operator knows
which one counts.

Each configured goal gets a tile of its own, behind the traffic tiles, with
the label the operator gave it rather than the one the analytics account uses.
Where a Matomo goal carries revenue, a second tile shows it, formatted as
money. It behaves like every other tile: its own sparkline, its own trend
badge against the previous period, its own place in the subpages rollup.

A goal's polarity is configurable too, because not every goal is one to
maximise. A "form abandoned" goal going up is bad news, and a tile that
coloured it green would say the opposite of what happened. See
[Polarity](extending.md#polarity) for what the three values do.

Goals are stored as ordinary metrics, so a site that configures none behaves
exactly as it did before: no extra tiles, and for Matomo not even a different
API request. Configuration is per provider:
[Matomo goals](providers/matomo.md#goals),
[GA4 key events](providers/ga4.md#key-events).

## Data age

The toolbar above the tiles marks which day the numbers are from with a small ⓘ, and hovering it names the date. It is a mark rather than a line of text: on a healthy installation it says the same thing every day, across five surfaces at once, and what it is there for is the day it stops doing that. That day is the last one the import treats as complete, which is not today: a scheduled run may only catch part of today, so it always revisits it and marks the previous day instead. The tiles therefore trail the calendar by a day even when everything is working.

Where a site has more than one provider the earliest of their days is shown, because the row is only as current as its oldest number. A provider that has never run at all contributes no day, since its tiles are empty anyway and letting it decide would hide how fresh the working provider is.

The date comes from the import's own resume cursor, which every run records, rather than from the window described under [Days without data](#days-without-data). That window can legitimately be unknown on a healthy installation, and saying nothing about the data's age is exactly the wrong answer to give about a scheduler that may have stopped.

More than two days behind, the mark changes to a clock and spells the warning out in words beside it. That is the point at which it stops being a footnote, so it stops behaving like one. At that point a scheduled import that simply has not run yet is no longer the likely explanation, and without this the backend would go on showing the last numbers it was given indefinitely, without ever saying they had stopped moving.

This is also why the popover's first two figures are called "Last day" and "Previous day" rather than "Today" and "Yesterday": they are the last two days of the selected period, which is rarely today and yesterday.

## Days without data

A sparkline distinguishes a day with no traffic from a day the extension has
no number for. The import records the window it has answers for, and days
outside that window are drawn as a gap in the line rather than a value of
zero: a 90-day period on a site with 60 days of history starts where the data
starts instead of climbing out of the floor. Hovering such a day says "No
data", and those days are left out of the popover's peak and average.

That window starts where the provider's data starts, not where the import
asked. `retentionDays` defaults to 400, so a first run requests well over a
year of history even from a site that is three weeks old, and treating the
request as the answer would put the cliff back.

Inside the imported window a missing day really is zero. A provider only
reports a URL that had activity, so an ordinary quiet day has no row of its
own and is shown as the zero it was.

Today is usually outside the window until the day's import has run, so the
most recent point commonly reads as no data rather than as a drop to zero.

Installations that have never run `page-metrics:import`, or whose data was
seeded straight into the database, have no window recorded. Nothing is marked
as missing then, since not knowing what is covered is no basis for claiming a
gap.

## Annotations

Annotations are events worth explaining a change in the numbers by, from three
independent sources that are always merged together:

- **Automatic**, derived by the `page-metrics:import` command from what TYPO3
  records anyway: a page whose record or content was edited gets a `content`
  marker for that day, and a redirect pointing at a page gets an `seo` marker
  for the day it was created. Nothing has to be set up and nothing has to be
  maintained, see [Automatic markers](#automatic-markers) below.
- **Imported**, via the same `page-metrics:import` command, from any
  registered `AnnotationProviderInterface` (mirroring
  `MetricProviderInterface`'s extensibility, but for events rather than
  per-URL metrics). The bundled Matomo provider imports Matomo's own
  annotations (e.g. release markers) — no extra configuration beyond an
  already-configured `providers.matomo` is needed. GA4 has no equivalent API
  to import from.
- **Manual**, created directly in the TYPO3 backend as a "Page metrics
  annotation" record: pick the site, a category, a date and a text. Manual
  annotations are the way to mark events on GA4-only sites, or anything not
  tied to an external API at all, and appear immediately, without waiting for
  the next import run. See [Adding a marker](#adding-a-marker) for where those
  records live and who may create one.

Annotations show up in two places: the markers on every sparkline
(page-tile popovers and the [dashboard chart widget](dashboard-widgets.md)
alike), and the dedicated
[annotations dashboard widget](dashboard-widgets.md).

### Adding a marker

Give the site a page for its manual annotations to live on:

```yaml
pageMetrics:
  annotations:
    storagePid: 42
```

With that set, **Web → Page** offers **Add marker** in the options menu beside
the tiles. It opens the record form with the site, today's date and the page
already filled in, and returns to the page module once the record is saved.

A marker created that way is an ordinary record on that page, so the ordinary
page permissions decide who may create one. An editor needs the "Page metrics
annotation" table under **Allowed tables** in their backend group, plus
content-edit permission on the storage page. The menu entry is shown only to a
user who has both, and TYPO3 checks the same two things again when the form is
saved.

Without `storagePid` there is no **Add marker** entry and nothing else changes:
records are still created through **New record** on whichever page you keep
them on. A marker is found by its "Site" field and never by the page it sits
on, so everything an installation already has, including the records at the
page tree's root from before this option existed, keeps appearing on the tiles
and stays editable where it is. Moving those onto the storage page is optional.

### Category

Every annotation has a category, which decides the colour and the icon it is
drawn with: release, campaign, content change, redesign, incident, SEO change
or external event. A marker's category is always spelled out in its tooltip
as well, so the colour is never the only thing carrying it, and the chart
widget can filter its markers down to a single category.

Annotations that predate this field, and anything imported from a provider
that has no notion of a kind (Matomo's own annotations, for instance), read
as "release" — which is what every annotation in this extension meant before
categories existed.

### Duration

Leave **Last day** empty for an event that happened on one day and it is
drawn as a tick. Set it for one that ran for a while, a campaign or a
freeze, and the chart draws a strip along its bottom edge spanning those
days instead. A strip that started before the shown period, or has not
ended within it, is still drawn: it is clamped to the edge of the chart
rather than dropped, because it is still explaining the days on screen.

### Page scope

Leave **Page** empty and the marker explains the whole site, which is what
every annotation did before this field existed. Pick a page and it only
appears on that page's tiles, plus, if **Also applies to subpages** is set,
on everything below it. Dashboard widgets are site-wide and show every
marker regardless of its page, since a marker on one page still explains a
bump in the site's totals.

### Automatic markers

Annotations that nobody maintains are empty annotations, so the import run
derives its own from records TYPO3 keeps regardless:

| Marker | Derived from | Says |
| --- | --- | --- |
| `content` | `sys_history`, rows for the page record itself and for the content elements on it | This page changed on this day |
| `seo` | `sys_redirect`, the day a redirect pointing at the page was created | A redirect now leads here |

They are folded per page and per day, so five edits on one afternoon are one
marker saying "Page updated, 5 changes", not five markers. They are always
page-scoped (they explain one page, never the whole site), they never span
days, and they are owned by the import: editing one by hand is pointless,
because the next run writes it again. Use a manual annotation for anything
you want to word yourself.

Only the last 90 days are derived, which is the longest period any tile or
widget draws. Workspace edits are ignored, since they explain nothing about
published traffic, and redirects pointing at an external URL or a bare path
are ignored too, because they name no page.

A marker says that a page changed, never who changed it: the backend user
`sys_history` records is not read at all.

The `seo` markers need EXT:redirects. Without it there is no `sys_redirect`
table, and only the `content` markers appear.

To turn the whole thing off for a site:

```yaml
pageMetrics:
  enabled: true
  annotations:
    automatic: false
```

Markers already written stay in the database; they simply stop being
refreshed.
