# Access log provider

Reads the site's own Apache/Nginx access log directly, no analytics service
needed. Add to a site's `config/sites/<identifier>/config.yaml`.

```yaml
pageMetrics:
  enabled: true
  providers:
    accesslog:
      pathPattern: '/var/log/nginx/example.com-access.log*'
```

`pathPattern` is passed to PHP's `glob()`, so a rotated set like `access.log`, `access.log.1`, `access.log-20261001.gz` is read as one set. Gzip is detected by content, not filename, so oddly named compressed rotations are still read correctly.

## Running it alongside Matomo or GA4

Metric identifiers are namespaced per provider (`matomo.pageviews`, `accesslog.pageviews`), so two providers can be configured on one site without their rows colliding. What they don't avoid on their own is two "Page views" tiles with different numbers, which is why a site's `metrics` key limits which of a provider's metrics the page module shows for it. The pairing this exists for is Matomo for audience numbers plus the access log for bot traffic, which Matomo cannot see at all since bots don't run its JavaScript:

```yaml
pageMetrics:
  enabled: true
  providers:
    matomo:
      endpoint: 'https://matomo.example.com/'
      siteId: 3
      tokenAuth: '%env(MATOMO_TOKEN)%'
    accesslog:
      pathPattern: '/var/log/nginx/example.com-access.log*'
      metrics:
        - machine
```

Matomo keeps the page views and visits tiles, the access log contributes only its bot buckets.

An entry is a metric identifier (`accesslog.visits`) or a group name (`audience`, `machine`). Prefer the group name: `machine` covers every bot bucket a provider offers, including ones added in a later release, where a hand-written list would not. [Bot traffic metrics](#bot-traffic-metrics) lists today's `machine` buckets. The key works on every provider and defaults to everything it offers.

Worth knowing:

- It filters what's displayed, not what's imported. Every metric keeps being written, so widening the list later shows full history immediately, no re-import needed.
- An entry naming a metric no installed provider offers is ignored, since provider availability isn't known when the site configuration is read. A malformed `metrics` key (not a non-empty list of non-empty strings) still fails to load.
- Rate metrics ("avg. time on page", "bounce rate") aren't covered by this key, they're read straight from the provider at display time. The access log offers none, so it's unaffected either way.

## Logs shared by several virtual hosts

Where a `LogFormat`/`log_format` ends in `%v`, each line is only counted for the site whose own host served it; lines for other hosts are skipped. Without this, every site sharing such a log would count every other site's traffic as its own.

A site's own hosts are its `base`, the `base` of each language, and any `pageMetrics.resolution.additionalHosts` entries. The comparison ignores case, a port, and a leading `www.`, since Apache writes the vhost's canonical `ServerName` into `%v`, which is often the bare domain while a site's `base` names the `www` one.

```yaml
pageMetrics:
  resolution:
    additionalHosts:
      - www.example.com
```

If your log carries `%v` and metrics come out empty, check this first: a `ServerName` of `shop.example.com` against a site `base` of `https://example.com/` matches nothing until `shop.example.com` is listed above. The import reports how many facts each run attributed, so a run that matched nothing says so.

A log with no `%v` token counts every line for the site, the common single-vhost case, unchanged. Lines for another host are dropped rather than recorded as unresolved URLs, since a foreign host that is itself a site here would otherwise resolve onto that site's own pages.

## How much it reads at once

The import emits each day as soon as the reader has moved a full day past it, rather than aggregating the whole window first, so it holds about two days of data regardless of `retentionDays` (default 400). The one day of slack absorbs a log whose lines straddle midnight or a rotated file ending shortly into the next day.

Files are read in order of their first entry, not by modification time or filename: backups and `rsync` without `-t` rewrite mtimes to the same instant, and rotation naming schemes (`access.log.1` vs `access.log-20261001.gz`) don't sort consistently. If a log set revisits a day already written, the import stops with an error naming the day, deliberately: page views and the visit set for that day would otherwise be double-counted or overwritten. Point `pathPattern` at one server's rotation set. A file that can't be read is skipped rather than failing the run.

Every matching file is still fully read; nothing tracks rotation progress across runs. But lines are date-checked before parsing, a substring comparison rather than a regex or date object, which is far cheaper: on a 1.44M-line, 120-day archive, reading plus date-checking every line takes 1.7s versus 6.4s to parse them all. On the same archive (119 gzipped files, one live plain one), decompression accounts for 2.33s of a 2.35s full re-read, so an old archive costs mostly in gzip, not line scanning.

Bot **agent** detail follows the same per-day boundary: rows are written and dropped as each day finishes, rather than held for the whole run. At 400 days, 500 URLs and 10 distinct crawlers, this keeps peak memory at 13 MB against 1945 MB for holding the full run. A run that dies partway keeps agent detail for finished days only; metric rows are written after the full range is read, so a provider that fails mid-read commits no metric rows at all, even if some agent detail was already flushed. The next successful run fills the metrics; agent rows are a diagnostic drill-down, not something summed elsewhere.

## What it can and cannot measure

Only the **combined** log format (Apache's and Nginx's shared default) is understood, a custom `LogFormat`/`log_format` is not. A log line carries no session or client-side signal, so this provider only offers:

- **Page views**: successful (2xx) and not-modified (304) `GET` requests. A 304 still counts as a view, it just needed no fresh body. Other 3xx responses are excluded, since the eventual 200 on the redirect target is what should count.
- **Visits**: distinct (IP, user agent) pairs per day, an approximation rather than a true session count. Shared IPs (NAT, proxies) undercount it; bots that slip past the filter overcount it.

"Avg. time on page" and "bounce rate" aren't offered, both need a client-side or session signal a raw log doesn't have. Use Matomo or GA4 for those.

Requests to `/typo3/` and `/typo3conf/`, and common static asset extensions (`.css`, `.js`, `.png`, `.woff2`, ...), are filtered before counting. Known crawler/bot user agents (search engines, SEO tools, generic HTTP clients like `curl`/`python-requests`) are filtered too, via a fixed, non-configurable signature list, a best-effort filter, not an exhaustive one.

## Bot traffic metrics

Every line `BotTaxonomy` can classify is sorted into one of five buckets and reported as its own per-page, per-day metric. Traffic it can't name (an unnamed vendor crawler, an empty user agent) falls into neither a bucket nor the human metrics.

- **`bot.ai_chatbot`**: AI chatbots fetching a page live for a specific user request.
- **`bot.ai_crawler`**: AI crawlers collecting content for training or an AI search index.
- **`bot.search`**: real search-engine indexers.
- **`bot.social`**: social platforms generating link previews.
- **`bot.tool`**: SEO tools, monitoring services and generic HTTP clients.

All five are `machine` metrics, so the page module folds them into one **Bot traffic** tile that breaks down when opened; the two AI buckets additionally get their own tile, counted in both places. See [Bot traffic](../page-tiles.md#bot-traffic).

These are counted differently from `accesslog.pageviews` on purpose: a bot hit counts regardless of HTTP status or method, since a bot hitting a dead link or backend probe is exactly the traffic these metrics exist to surface. A similar but not identical static-asset exclusion still applies (pageviews also excludes `.pdf`/`.xml`/`.json`/`.txt`, the bot filter doesn't), so `/robots.txt` can be bucketed as bot traffic despite being excluded from pageviews.

Which specific bot made those hits, and whether its request path looked spoofed (a known bot's user agent paired with a path real bots don't use), is recorded in `tx_typo3pagemetrics_bot_agent_daily`, one row per site/page/day/bucket/agent, with a `hits_suspicious` column. The two AI buckets surface that detail in their tiles, see [AI chatbots and AI crawlers](../page-tiles.md#ai-chatbots-and-ai-crawlers). The other three buckets' agents, and the spoofing count of all five, are queryable only in the database today.

For ad-hoc inspection of a log file without touching the database, use:

```bash
vendor/bin/typo3 page-metrics:analyze /var/log/nginx/example.com-access.log*
```

A full bot-traffic backend module, built on the agent- and spoofing-level
detail above, is planned but out of scope for this feature.
