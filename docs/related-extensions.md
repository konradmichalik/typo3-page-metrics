# Related extensions

`typo3_page_metrics` reads an analytics provider you already operate. It
tracks nothing itself and sends no data anywhere, which also means it can
only show what your provider already collects.

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
- [analytics_matomo](https://github.com/maikschneider/analytics-matomo) shows
  a statistics bar for the current page in the page module, queried live from
  Matomo for that page's URL and cached for an hour. Pick it if you only use
  Matomo and want that view without an import or tables of its own; it asks
  Matomo for the page's own URL, so traffic on record detail URLs or old URLs
  behind a redirect does not count for the page.
