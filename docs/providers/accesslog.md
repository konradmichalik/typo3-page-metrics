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

`pathPattern` is passed to PHP's `glob()`, so a rotated set like `access.log`, `access.log.1`, `access.log-20261001.gz` is read as one set. Gzip is detected by the file's own content rather than its name, so hosts that rotate into extensionless or oddly suffixed compressed files are read correctly too.

The matched files are read oldest first, by modification time rather than by name: rotation schemes disagree about naming and several of them sort the wrong way round alphabetically.

## How much it reads at once

The import emits each day as soon as the reader has moved a full day past it, rather than aggregating the whole requested window first. It therefore holds about two days at a time regardless of how far back `retentionDays` reaches, which matters because the default is 400 days and a distinct-visitor set per day and page is millions of entries over that span.

The one day of slack absorbs a log whose lines straddle midnight, or a rotated file that ends a few entries into the next day.

Files are read in the order of the first entry each one contains, not by modification time or by name. Modification times look like the obvious key and are not one: a restore from backup, an `rsync` without `-t` or a plain `cp` rewrites them all to the same instant. Names are no better, since rotation schemes disagree (`access.log.1`, `access.log-20261001.gz`) and several of them sort the wrong way round alphabetically.

If a log set still revisits a day that has already been written, the import for that provider stops with an error naming the day. That happens when one pattern matches logs from several servers, and it is deliberate: page views would be counted twice, the visit count's distinct set for the day is already gone, and the unresolved-URL store replaces rather than sums, so it would keep only the last fragment. Point the pattern at one server's rotation set.

A file that cannot be read is skipped rather than failing the run, since a glob over a rotation directory routinely picks up one the web user has no access to.

Note that the bot **agent** detail (which specific crawler, see below) is still collected for the whole run before it is written, so a first import over a very long window with heavy crawler traffic can still be memory-hungry.

## What it can and cannot measure

Only the **combined** log format (Apache's and Nginx's shared default) is
understood - a custom `LogFormat`/`log_format` is not. Since a log line
carries no session or client-side signal, this provider only offers:

- **Page views**: successful (2xx) `GET` requests for the page.
- **Visits**: distinct (IP address, user agent) pairs that requested the
  page on the same day - an approximation, not a true session count. Shared
  IPs (NAT, corporate proxies) undercount it; IP-rotating bots that slip
  past the bot filter overcount it.

"Avg. time on page" and "bounce rate" are not offered: both need a
client-side or session signal a raw access log does not have. Use Matomo or
GA4 if those tiles matter to you.

Requests to `/typo3/` and `/typo3conf/`, and requests for common static
asset extensions (`.css`, `.js`, `.png`, `.woff2`, ...), are filtered out
before counting - otherwise "page views" would mostly be asset hits.
Known crawler/bot user agents (search engines, SEO tools, generic HTTP
clients like `curl`/`python-requests`) are filtered too, via a fixed,
non-configurable signature list - a best-effort filter, not an exhaustive
one.

## Bot traffic metrics

Every line classified by `BotTaxonomy` is not simply discarded - it is
sorted into one of five buckets and reported as its own per-page, per-day
metric. Traffic `BotTaxonomy` cannot name (an unnamed vendor crawler, a
generic script, an empty user agent) falls into neither a bucket nor the
human metrics - there is no bucket for a bot nobody has a name for.

- **`bot.ai_chatbot`**: AI chatbots fetching this page live in response to
  a specific user request.
- **`bot.ai_crawler`**: AI crawlers collecting content for model training
  or an AI search index.
- **`bot.search`**: real search-engine indexers building their search
  index.
- **`bot.social`**: social platforms generating link previews for shared
  URLs.
- **`bot.tool`**: SEO tools, monitoring services and generic HTTP clients.

All five are declared as machine metrics, so the page module folds them into a single **Bot traffic** tile that breaks itself down when opened, rather than putting five crawler counts beside the two numbers an editor came for. See [Bot traffic](../page-tiles.md#bot-traffic). The storage is untouched: each bucket keeps its own identifier and its own rows.

These are counted differently from `accesslog.pageviews` on purpose:
pageviews only count successful (2xx) `GET` requests, but a bot hit counts
regardless of HTTP status or method. A bot hitting a dead link, a
redirect, or a backend probe is exactly the traffic these metrics exist to
surface, not traffic to discard before it can be measured. A similar (but not
identical) static-asset exclusion to pageviews' still applies, so a bot's own
CSS/JS/image requests are never counted toward any bucket - the two lists
differ slightly (for example pageviews also excludes `.pdf`/`.xml`/`.json`/
`.txt`, which the bot-side filter does not), so a path like `/robots.txt`
can still be bucketed as bot traffic even though it is excluded from
pageviews.

The five tiles only show a total per page per day. Which specific bot
made those hits, and whether its request path looked spoofed (a known
bot's user agent paired with a path pattern real bots do not use), is
recorded separately in the `tx_typo3pagemetrics_bot_agent_daily` table -
one row per site/page/day/bucket/agent, with a `hits_suspicious` column
for the spoofing count. This detail is not yet surfaced in any tile or
backend view; today it is only queryable directly in the database.

For ad-hoc inspection of a log file without touching the database at all -
useful for checking what a bucket or the spoofing signal would look like
before importing anything - use the `page-metrics:analyze` console
command:

```bash
vendor/bin/typo3 page-metrics:analyze /var/log/nginx/example.com-access.log*
```

A full bot-traffic backend module, built on top of the agent- and
spoofing-level detail above, is planned but out of scope for this
feature. See
[`docs/superpowers/specs/2026-09-11-bot-inspect-merge-design.md`](../superpowers/specs/2026-09-11-bot-inspect-merge-design.md)
for that design.
