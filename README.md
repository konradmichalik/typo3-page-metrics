<div align="center">

# TYPO3 extension `page_metrics`

[![License](https://poser.pugx.org/konradmichalik/typo3-page-metrics/license)](LICENSE.md)
[![Supported TYPO3 versions](https://badgen.net/badge/TYPO3/13%20%26%2014/orange)](https://extensions.typo3.org/extension/page_metrics)

</div>

Page-level metrics inside the TYPO3 backend, from swappable data sources.

Resolves an arbitrary URL (from a Matomo export or an access log) back to a
TYPO3 page UID — including redirect chains and multilingual slugs — then
stores per-page daily aggregates so editors can see "how is this page doing"
without leaving the backend.

> [!NOTE]
> For dashboard-level Matomo reporting, use
> [`brotkrueml/typo3-matomo-widgets`](https://extensions.typo3.org/extension/matomo_widgets)
> instead. This extension is for page-level metrics, not dashboards — the
> two are complementary, not competing.

## Features

- URL → page resolver with redirect-chain and multilingual slug support,
  persisted at import time (never resolved live, never retroactively)
- Source-agnostic metric storage via `MetricSourceInterface` (`@api`) —
  Matomo is included, more sources can register without touching the core
- `page-metrics:import` console command for scheduled or manual imports,
  idempotent by default, `--force` to re-import the full retention window
- `Web > Page Metrics` backend module: per-page metric tiles with a period
  switch, a 30-day sparkline popover, and an unresolved-URL indicator
- Only aggregates are stored — no IPs, no user agents, no sub-day timestamps

## Installation

```bash
composer require konradmichalik/typo3-page-metrics
```

## Configuration

Add to a site's `config/sites/<identifier>/config.yaml`:

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
  retentionDays: 400
```

If `brotkrueml/typo3-matomo-widgets` is already installed and configured for
the site, its `matomoWidgetsUrl`/`matomoWidgetsIdSite`/`matomoWidgetsTokenAuth`
keys are used as a fallback when this extension's own `sources.matomo` is
not set — no need to configure the same Matomo instance twice.

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
definitions Matomo's tiles use - see each tile's own description text.

## Usage

Run the import (also schedulable via the TYPO3 Scheduler):

```bash
vendor/bin/typo3 page-metrics:import
vendor/bin/typo3 page-metrics:import --force
```

Then open **Web → Page Metrics** and select a page in the page tree.

## Development

```bash
composer install
composer test
composer cgl sca
```

## License

GPL-2.0-or-later, see [`LICENSE.md`](LICENSE.md).
