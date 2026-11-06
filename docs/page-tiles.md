# Page module tiles

Opening **Web → Page** and selecting a page shows metric tiles below the page
title, fetched via AJAX after the page module itself has loaded.

![Page metrics tiles below the page title, with the period switch above them](img/page-tiles.png)

## Period switch

A `7 / 30 / 90 days` switch above the tiles controls the period for every
tile and its sparkline. The choice is carried in the URL, so it survives a
reload or a bookmark.

## Including subpages

When the page has subpages, an options menu (`⋮`) offers **Include
subpages**, aggregating the tiles across the whole subtree instead of just
the current page. Useful for a section landing page whose traffic sits on
its children.

## Sparkline popover

Clicking or focusing a tile opens a popover with a day-by-day sparkline, the
period's key figures (last day, previous day, peak, average, redirect
share), a link to the source (e.g. "Open in Matomo"), and a "Typical page"
comparison: the median across every page of the same site, plus, for count
metrics, a rough percentile band. Rate metrics get the median but no band,
since "top fifth" would flip meaning between a page-views tile and a
bounce-rate tile. Pages or periods with no data are excluded from the
comparison, which needs at least two pages to appear and five to name a
band.

![Sparkline popover for the Visits tile, showing last day/previous day/peak/average and a dated tooltip](img/page-tiles-sparkline-popover.png)

Annotations falling in the shown range are marked as dashed lines (a single
day) or a strip along the bottom (a range). See [Annotations](#annotations).

## Bot traffic

When the access-log provider is configured, its five bot buckets fold into
one grey **Bot traffic** tile rather than five extra ones; the popover
breaks down what each bucket counts. See [bot traffic
metrics](providers/accesslog.md#bot-traffic-metrics) for what each bucket
is.

### AI chatbots and AI crawlers

Two of the five buckets, **AI chatbots** (live per-request fetches) and
**AI crawlers** (training/index crawls), also get their own tile before the
summary and count towards it too. Their popovers name the bots behind the
number (`GPTBot`, `ClaudeBot`, `ChatGPT-User`, ...) with a short description
on hover, drawn from the agent detail the import records. Neither tile is
benchmarked against the rest of the site, being crawled more is not an
achievement. See [Audience and machine
metrics](extending.md#audience-and-machine-metrics) for how a provider
marks a metric as one or the other.

## Goal tiles

A configured Matomo goal or GA4 key event gets its own tile behind the
traffic tiles, labelled as the operator named it, with a revenue tile
alongside if the goal carries one. A goal's polarity is configurable, since
not every goal should read as "more is better", see
[Polarity](extending.md#polarity). Configuration is per provider: [Matomo
goals](providers/matomo.md#goals), [GA4 key events](providers/ga4.md#key-events).

## Where visitors came from

The visits (Matomo) or sessions (GA4) tile's popover breaks its number down
by traffic channel, always in the same order:

| Channel | What lands in it |
| --- | --- |
| `Direct` | No referrer at all: typed, bookmarked, or an app that sends none |
| `Search` | A search engine's own results |
| `Social` | A social network |
| `Referral` | Another website linking here |
| `Campaign` | Traffic carrying campaign tagging |
| `Other` | Traffic the provider could not place |

Both providers map their own vocabulary onto this list; provider-specific
edge cases are documented per provider: [Matomo](providers/matomo.md#traffic-channels),
[GA4](providers/ga4.md#traffic-channels). A channel with no traffic in the
period is left out.

## Data age

The toolbar marks which day the numbers are from. That is the last day the
import treats as complete, usually yesterday, since a scheduled run may
only have caught part of today. With more than one provider, the earliest
of their days is shown. More than two days behind, the mark turns into a
warning, the point to check whether the scheduled import is still running.

## Days without data

A sparkline shows a day with no traffic differently from a day the
extension has no number for at all: the latter is drawn as a gap rather
than a zero. That window starts where the provider's own data starts, not
where `retentionDays` (default 400) asked back to.

## Annotations

Events worth explaining a change in the numbers by, merged from three
sources:

- **Automatic**, derived by `page-metrics:import` from what TYPO3 already
  records: a `content` marker when a page or its content changes, an `seo`
  marker the day a redirect to it is created. See [Automatic
  markers](#automatic-markers).
- **Imported**, from a registered `AnnotationProviderInterface`. The
  bundled Matomo provider imports Matomo's own annotations (e.g. release
  markers); GA4 has no equivalent API.
- **Manual**, created in the TYPO3 backend as a "Page metrics annotation"
  record. The only option for GA4-only sites or events tied to no external
  API. See [Adding a marker](#adding-a-marker).

They appear as markers on every sparkline and in the [annotations dashboard
widget](dashboard-widgets.md).

### Adding a marker

Give the site a page for manual annotations:

```yaml
pageMetrics:
  annotations:
    storagePid: 42
```

With that set, **Web → Page** offers **Add marker** in the options menu,
pre-filled with the site, page and today's date. Creating one needs the
"Page metrics annotation" table under **Allowed tables** plus content-edit
permission on the storage page. Without `storagePid`, records are still
created through **New record** on whichever page you choose.

### Category

Every annotation has a category (release, campaign, content change,
redesign, incident, SEO change, external event), shown in its icon and
tooltip, never by colour. Annotations with no category, including all
pre-existing ones, read as "release".

### Duration

Leave **Last day** empty for a one-day event, drawn as a tick. Set it for a
campaign or freeze that ran for a while, drawn as a strip.

### Page scope

Leave **Page** empty for a site-wide marker. Pick a page to scope it to
that page (and, with **Also applies to subpages**, its subtree). Dashboard
widgets always show every marker regardless of page.

### Automatic markers

| Marker | Derived from | Says |
| --- | --- | --- |
| `content` | `sys_history`, for the page record and its content elements | This page changed on this day |
| `seo` | `sys_redirect`, the day a redirect to the page was created | A redirect now leads here |

Multiple edits on one day fold into one marker. Only the last 90 days are
derived, workspace edits are ignored, and `seo` markers need EXT:redirects
installed. Turn them off per site:

```yaml
pageMetrics:
  enabled: true
  annotations:
    automatic: false
```

### Opening a marker

Clicking a marker on a sparkline opens a day panel with every marker that
explains that day and what each metric did on it specifically.

### Before and after

For a page-scoped marker, the panel compares the 14 days before it against
the 14 days after, metric by metric (never rates, since those already
divide the change away). The marker's own day counts for neither side. If
either window still has unimported days, the row says so instead of
showing a number. Site-wide markers get no comparison.
