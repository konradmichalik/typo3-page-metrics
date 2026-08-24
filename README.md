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
> instead. This extension is for page-level metrics, not dashboards.

## Features

- URL → page resolver with redirect-chain and multilingual slug support
- Source-agnostic metric storage (`MetricSourceInterface`), Matomo included
- `page-metrics:import` command for scheduled or manual imports
- Backend module with per-page metric tiles and a 30-day sparkline

## Installation

```bash
composer require konradmichalik/typo3-page-metrics
```

## Development

```bash
composer install
composer test
composer cgl sca
```

## License

GPL-2.0-or-later, see [`LICENSE.md`](LICENSE.md).
