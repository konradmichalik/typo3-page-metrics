# GA4 provider

> [!WARNING]
> GA4 support is untested against a live GA4 property. Please report issues
> if you run into any.

GA4 is supported as an alternative provider, for sites without Matomo. Add to
a site's `config/sites/<identifier>/config.yaml`.

```yaml
pageMetrics:
  enabled: true
  providers:
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
with `--force` (see the main [README](../../README.md#usage)) to pick up
corrections to those days.
