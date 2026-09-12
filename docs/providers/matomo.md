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

## Goals

Matomo knows dozens of goals; which of them an editor is meant to act on is a
business question, so goals are declared per site rather than discovered from
the API.

```yaml
pageMetrics:
  enabled: true
  providers:
    matomo:
      endpoint: 'https://matomo.example.com/'
      siteId: 3
      tokenAuth: '%env(MATOMO_TOKEN)%'
  goals:
    - provider: matomo
      id: 3
      label: 'Newsletter signup'
    - provider: matomo
      id: 'ecommerceOrder'
      label: 'Orders'
      revenue: true
```

| Key | Meaning |
| --- | --- |
| `provider` | Which provider the goal belongs to, `matomo` here |
| `id` | The Matomo goal id, or `ecommerceOrder` for the ecommerce order goal |
| `label` | What the tile is called. Yours, not Matomo's |
| `polarity` | `higher_is_better` (default), `lower_is_better` or `neutral` |
| `revenue` | `true` adds a second tile with the revenue Matomo attributes to that goal |

Each goal becomes one tile, stored as an ordinary metric
(`matomo.goal.<id>.conversions`, and `matomo.goal.<id>.revenue` where revenue
is on), so no schema change and no separate import step is involved. The
importer adds `idGoal=0` and `filter_update_columns_when_show_all_goals=1` to
the `Actions.getPageUrls` call it already makes, so conversions cost no extra
request. A site with no goals sends exactly the request it always sent.

Revenue is stored in minor units (an integer count of cents) and shown with
two decimals. Every value in this extension's fact table is a plain integer in
its own unit, and money is no exception: daily rows have to sum exactly. No
currency symbol is shown, because the currency is a setting of the Matomo
site rather than something the API reports per row, so put it in the goal's
own label if it matters ("Orders revenue (EUR)").

A goal id is copied verbatim into the metric identifier, which is why it is
restricted to letters, digits, `_`, `.` and `-`. Renaming an id later is a
data migration, the same as for any other metric identifier.
