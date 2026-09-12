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
falls in the shown range; hovering or focusing a marker's date shows its
text in the tooltip alongside that day's value.

![Sparkline popover for the Visits tile, showing last day/previous day/peak/average and a dated tooltip](img/page-tiles-sparkline-popover.png)

## Data age

The toolbar above the tiles says which day the numbers are from, as "As of <date>". That day is the last one the import treats as complete, which is not today: a scheduled run may only catch part of today, so it always revisits it and marks the previous day instead. The tiles therefore trail the calendar by a day even when everything is working.

Where a site has more than one provider the earliest of their days is shown, because the row is only as current as its oldest number. A provider that has never run at all contributes no day, since its tiles are empty anyway and letting it decide would hide how fresh the working provider is.

The date comes from the import's own resume cursor, which every run records, rather than from the window described under [Days without data](#days-without-data). That window can legitimately be unknown on a healthy installation, and saying nothing about the data's age is exactly the wrong answer to give about a scheduler that may have stopped.

More than two days behind, the date is marked as stale with an icon and a note. At that point a scheduled import that simply has not run yet is no longer the likely explanation, and without this the backend would go on showing the last numbers it was given indefinitely, without ever saying they had stopped moving.

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

Annotations are site-wide, point-in-time events, from two independent
providers that are always merged together:

- **Imported**, via the same `page-metrics:import` command, from any
  registered `AnnotationProviderInterface` (mirroring
  `MetricProviderInterface`'s extensibility, but for events rather than
  per-URL metrics). The bundled Matomo provider imports Matomo's own
  annotations (e.g. release markers) — no extra configuration beyond an
  already-configured `providers.matomo` is needed. GA4 has no equivalent API
  to import from.
- **Manual**, created directly in the TYPO3 backend as a "Page metrics
  annotation" record — pick the site, a date and a text. An annotation is
  scoped by that "Site" field alone, not by its position in a page tree,
  so the record lives at the absolute root (`rootLevel = 1`, the same
  convention as `be_users`/`be_groups`) rather than under any one page:
  open the root page in the page tree (the extension/site name at the
  very top) and use **New record** there. This also means only backend
  admins can create one, the same restriction TYPO3 applies to any
  root-level record. Manual annotations are the way to mark events on
  GA4-only sites, or anything not tied to an external API at all, and
  appear immediately, without waiting for the next import run.

Annotations show up in two places: the marker ticks on every sparkline
(page-tile popovers and the [dashboard chart widget](dashboard-widgets.md)
alike), and the dedicated
[annotations dashboard widget](dashboard-widgets.md).
