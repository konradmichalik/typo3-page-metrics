<div align="center">

![Extension icon](Resources/Public/Icons/Extension.png)

# TYPO3 extension `typo3_page_metrics`

[![License](https://poser.pugx.org/konradmichalik/typo3-page-metrics/license)](LICENSE.md)
[![Supported TYPO3 versions](https://badgen.net/badge/TYPO3/13.4%20%7C%2014.3/orange)](https://extensions.typo3.org/extension/typo3_page_metrics)

</div>

Page-level metrics inside the TYPO3 backend, from an analytics source you
already run.

No tracking code, no third-party service, no subscription: the extension reads
your existing Matomo or GA4 data and resolves every URL it reports — through
redirect chains and multilingual slugs — back to a TYPO3 page UID, then stores
per-page daily aggregates so editors can see "how is this page doing" without
leaving the backend.

![Page metrics tiles below the page title, with the period switch above them](docs/img/page-tiles.png)

## ✨ Features

- URL → page UID resolution at import time, through redirect chains and
  multilingual slugs, so every number hangs off a page in the tree rather than
  a URL string: tiles link straight into the page module, a section can roll
  its whole subtree up into one figure, and URLs that resolve to no page are
  reported as [Not found pages](docs/dashboard-widgets.md)
- Metric tiles right in `Web > Page`, below the page title: page views,
  visits, avg. time on page, bounce rate, a period switch (7/30/90 days),
  and a sparkline popover per tile — see [Page module tiles](docs/page-tiles.md)
- Site-wide [EXT:dashboard](https://docs.typo3.org/permanent/typo3/cms-dashboard/main/en-us/)
  widgets plus a ready-made dashboard preset — see
  [Dashboard widgets](docs/dashboard-widgets.md)
- Site-wide annotations, imported (e.g. Matomo release markers) or created by
  hand, marked on every sparkline — see
  [Annotations](docs/page-tiles.md#annotations)
- Matomo and GA4 as pluggable data sources, extensible with your own — see
  [Registering custom tiles](docs/extending.md)

## 🧭 Related extensions

`typo3_page_metrics` reads an analytics source you already operate. It tracks
nothing itself and sends no data anywhere, which also means it can only show
what your source already collects.

- [TYPO3 Analytics](https://analytics.typo3.com/) (`t3g/analytics`) is the
  opposite trade-off: a hosted analytics service by TYPO3 GmbH that registers
  your site, injects its own tracking script and shows the results in the
  backend. Pick it if you want analytics *and* the backend integration in one
  paid package, rather than a backend view onto an analytics setup you already
  run.
- [matomo_widgets](https://github.com/brotkrueml/typo3-matomo-widgets) puts
  Matomo's own reports on the TYPO3 dashboard, live via the Reporting API.
  Pick it if you want Matomo's reports as-is; it deliberately stays in Matomo's
  URL world and does not map anything onto the page tree.

## 🔥 Installation

### Requirements

- PHP 8.2, 8.3, 8.4 or 8.5
- TYPO3 13.4 or 14.0+
- A Matomo instance with API access, and/or a GA4 property with a
  service-account key

### Composer setup

```bash
composer require konradmichalik/typo3-page-metrics
```

Then configure a metric source for the site, see [Configuration](#configuration).

## ⚙️ Configuration

Add to a site's `config/sites/<identifier>/config.yaml`.

### Matomo

```yaml
pageMetrics:
  enabled: true
  sources:
    matomo:
      endpoint: 'https://matomo.example.com/'
      siteId: 3
      tokenAuth: '%env(MATOMO_TOKEN)%'
  resolution:
    dropQueryString: true
    keepQueryParams: ['tx_solr[q]']
    additionalHosts: ['www.example.com']
  retentionDays: 400
```

`resolution.additionalHosts` lets a site accept URLs reported under a host
other than its own `base` (and each language's base) as belonging to it, the
page lookup still uses the local page tree. This is for Dev/Stage instances
whose `base` differs from production: the analytics source (e.g. Matomo)
always reports the production domain, so importing on an environment with a
different `base` would otherwise resolve nothing.

### GA4

> [!WARNING]
> GA4 support is untested against a live GA4 property. Please report issues
> if you run into any.

GA4 is supported as an alternative source, for sites without Matomo:

```yaml
pageMetrics:
  enabled: true
  sources:
    ga4:
      propertyId: '123456789'
      serviceAccountKeyFilePath: '%env(GA4_KEY_FILE_PATH)%'
```

The key file is a Google Cloud service-account JSON key (never inline
credentials) with read access to the GA4 property's Data API. "Avg. time
on page" and "bounce rate" are GA4 approximations, not the same
definitions Matomo's tiles use: see each tile's own description text.

GA4's Data API can take 24-48 hours to finalize a day's data, so its most
recently imported days may be understated. Periodically re-run the import
with `--force` (see [Usage](#usage)) to pick up corrections to those days.

## ⚡ Usage

Run the import (also schedulable via the TYPO3 Scheduler). The same command
imports both metrics and any annotations from a registered source (e.g.
Matomo release markers, see [Page module tiles](docs/page-tiles.md#annotations)):

```bash
vendor/bin/typo3 page-metrics:import
vendor/bin/typo3 page-metrics:import --force
```

Then open **Web → Page** and select a page in the page tree — the tiles
appear directly below the page title. See
[Page module tiles](docs/page-tiles.md) for the period switch, the subpages
toggle and the sparkline popover, or
[Dashboard widgets](docs/dashboard-widgets.md) for the site-wide EXT:dashboard
widgets.

## 📚 Documentation

| Page | What's inside |
| --- | --- |
| [Dashboard widgets](docs/dashboard-widgets.md) | The full EXT:dashboard widget catalog, their settings and the bundled preset |
| [Page module tiles](docs/page-tiles.md) | The period switch, the subpages toggle, sparkline popovers and annotations |
| [Security](docs/security.md) | Restricting tile visibility per backend user group |
| [Registering custom tiles](docs/extending.md) | Contributing an additional tile via `ModifyMetricTilesEvent`, without implementing `MetricSourceInterface` |

## 🧑‍💻 Contributing

Please have a look at [`CONTRIBUTING.md`](CONTRIBUTING.md).

## 🏅 Credits

Tile icons are from [Tabler Icons](https://tabler.io/icons), MIT licensed.

## ⭐ License

GPL-2.0-or-later, see [`LICENSE.md`](LICENSE.md).
