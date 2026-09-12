# Matomo provider

Add to a site's `config/sites/<identifier>/config.yaml`.

```yaml
pageMetrics:
  enabled: true
  providers:
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
whose `base` differs from production: the analytics provider (e.g. Matomo)
always reports the production domain, so importing on an environment with a
different `base` would otherwise resolve nothing.
