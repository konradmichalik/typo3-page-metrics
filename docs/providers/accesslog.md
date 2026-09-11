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

`pathPattern` is passed to PHP's `glob()`, so a rotated set like
`access.log`, `access.log.1`, `access.log-20261001.gz` is read as one set.
`.gz` files are decompressed transparently; everything else is read as
plain text.

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

Every line this provider filters out of pageviews/visits as bot traffic is
not simply discarded - it is classified into one of five buckets and
reported as its own per-page, per-day metric:

- **`bot.ai_chatbot`**: AI chatbots fetching this page live in response to
  a specific user request.
- **`bot.ai_crawler`**: AI crawlers collecting content for model training
  or an AI search index.
- **`bot.search`**: real search-engine indexers building their search
  index.
- **`bot.social`**: social platforms generating link previews for shared
  URLs.
- **`bot.tool`**: SEO tools, monitoring services and generic HTTP clients.

These are counted differently from `accesslog.pageviews` on purpose:
pageviews only count successful (2xx) `GET` requests, but a bot hit counts
regardless of HTTP status or method. A bot hitting a dead link, a
redirect, or a backend probe is exactly the traffic these metrics exist to
surface, not traffic to discard before it can be measured. The same
static-asset exclusion as pageviews still applies, so a bot's own
CSS/JS/image requests are never counted toward any bucket.

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
