# GA4 provider

> [!NOTE]
> Verified against a live GA4 property: authentication, the Data API's
> `runReport` call, and the daily import all confirmed working end to end.

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

## Key events

GA4 calls a conversion a key event. Which key events matter is a business
question, so they are declared per site, in the same `pageMetrics.goals` list
the [Matomo provider](matomo.md#goals) uses.

```yaml
pageMetrics:
  enabled: true
  providers:
    ga4:
      propertyId: '123456789'
      serviceAccountKeyFilePath: '%env(GA4_KEY_FILE_PATH)%'
  goals:
    - provider: ga4
      id: 'generate_lead'
      label: 'Lead'
    - provider: ga4
      id: 'purchase'
      label: 'Purchase'
```

`id` is the GA4 event name exactly as the property records it. `label`,
`polarity` and the rest behave as they do for Matomo, except `revenue`, which
has no equivalent here: GA4 reports event value separately from key events,
and a revenue tile that quietly counted something else would be worse than
none.

Each key event becomes one tile, stored as the ordinary metric
`ga4.key_event.<name>`. Unlike Matomo's goal columns, these cannot ride along
in the page report: splitting a key event by name needs the `eventName`
dimension, and adding that dimension to the existing report would split every
page-view row by event name too. So a site with key events configured sends
one additional `runReport` per import, filtered server-side to the configured
names. A site without them sends exactly the requests it always sent.

GA4's Data API can take 24-48 hours to finalize a day's data, so its most
recently imported days may be understated. Periodically re-run the import
with `--force` (see the main [README](../../README.md#usage)) to pick up
corrections to those days.

<details>
<summary><strong>Setting up GA4 access (click to expand)</strong></summary>

A service account is Google's mechanism for server-to-server access, without
a human user or a browser login involved. It needs to exist in a Google
Cloud project and then be given read access to the specific GA4 property.

1. **Enable the Data API.** In the
   [Google Cloud Console](https://console.cloud.google.com), pick or create
   a project, then under *APIs & Services → Library* enable the
   **Google Analytics Data API**.
2. **Create a service account.** Under *APIs & Services → Credentials →
   Create Credentials → Service account*, give it any name (e.g.
   `page-metrics-ga4-reader`). No project-level IAM role is required, GA4
   access is granted separately in step 4.
3. **Download a JSON key.** Open the new service account, tab *Keys → Add
   key → Create new key → JSON*. This file contains `client_email` and
   `private_key`, treat it exactly like a password.
4. **Grant the service account access to the property.** In
   [Google Analytics](https://analytics.google.com), go to *Admin → Property
   Access Management* (or *Account Access Management* if the property-level
   one is not visible), add the service account's email address (the
   `...@<project>.iam.gserviceaccount.com` one from the JSON key) with role
   **Viewer**. Without this step every import fails with an authentication
   or permission error, the JSON key alone is not enough.
5. **Note the property ID.** In *Admin → Property Details*, copy the numeric
   property ID (not the "G-" measurement ID from the data stream).

Place the downloaded key file outside the webroot and outside version
control, then reference its path via an environment variable, never as a
literal path or inline content in `config.yaml`. For local development with
DDEV, `.env`/`.env.local` files are not always read by every project's
dotenv setup (some, like `helhum/dotenv-connector`'s default adapter, only
load a single `.env` file with no cascade); setting the variable in
`.ddev/config.local.yaml` under `web_environment` works regardless of the
project's own PHP-level environment loading, since it is injected at the
container level.

```yaml
# .ddev/config.local.yaml (gitignored)
web_environment:
  - GA4_KEY_FILE_PATH=/var/www/html/config/ga4/service-account.json
```

</details>
