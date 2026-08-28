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

![Sparkline popover for the Visits tile, showing today/yesterday/peak/average and a dated tooltip](img/page-tiles-sparkline-popover.png)

## Annotations

Annotations are site-wide, point-in-time events, from two independent
sources that are always merged together:

- **Imported**, via the same `page-metrics:import` command, from any
  registered `AnnotationSourceInterface` (mirroring
  `MetricSourceInterface`'s extensibility, but for events rather than
  per-URL metrics). The bundled Matomo source imports Matomo's own
  annotations (e.g. release markers) — no extra configuration beyond an
  already-configured `sources.matomo` is needed. GA4 has no equivalent API
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
