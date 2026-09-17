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

## Running it alongside Matomo or GA4

Two providers can be configured on one site and the import keeps them apart, because metric identifiers are namespaced per provider: `matomo.pageviews` and `accesslog.pageviews` are separate rows that never collide. The page module is where that stops being helpful. Left alone it renders both, and two tiles called "Page views" showing different numbers is the worst possible answer to the question of how many page views a page got.

The `metrics` key says which of a provider's metrics this site wants from it. The pairing that motivates the option is Matomo for the audience numbers and the access log for the bot traffic only, which Matomo cannot see at all because bots do not run its JavaScript:

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

Matomo keeps the page views and visits tiles, the access log contributes its bot buckets and nothing else.

An entry is either a metric identifier such as `accesslog.visits`, or the name of a metric group: `audience` or `machine`. Prefer the group name. `machine` means every bot bucket the provider offers, including the ones it gains in a later release, where five identifiers written out by hand quietly stay five when a sixth bucket appears. [Bot traffic metrics](#bot-traffic-metrics) lists what is in the machine group today.

The key works on every provider, not only this one, and defaults to everything the provider offers. A site that names no `metrics` key keeps every tile it has today.

Three things worth knowing before using it:

- It filters what is displayed, not what is imported. The rows keep being written for every metric the provider produces, so widening the list later shows the history back to the first import rather than only what arrived after the change. No re-import and no `--force` run is needed.
- An entry naming a metric that no installed provider offers is ignored rather than refused. Whether an identifier is real depends on which provider extensions are installed, and a site configuration is read long before that is known. A malformed `metrics` key is still refused outright: it has to be a non-empty list of non-empty strings, and the site configuration fails to load otherwise.
- Rate metrics are not covered yet. "Avg. time on page" and "Bounce rate" are derived at display time and are read straight from the provider rather than through the metric list, so pairing Matomo with GA4 still shows both of their "Avg. time on page" tiles. The access log offers no rate metrics, so the pairing above is unaffected.

## Logs shared by several virtual hosts

A `LogFormat`/`log_format` ending in `%v` writes the virtual host that served each line, and shared hosting commonly puts several vhosts into one combined log. Where the token is present, a line is only counted for the site whose own host served it; a line for any other host is skipped. Without that check every site configured against such a log would count every other site's traffic as its own, with nothing in the numbers to show it.

A site's own hosts are its `base`, the `base` of each of its languages (so a language on its own domain keeps its traffic and its language), and every entry in `pageMetrics.resolution.additionalHosts`. The comparison ignores capitalisation, a port, and a leading `www.`: Apache writes the vhost's canonical `ServerName` into `%v`, which is routinely the bare domain where the site `base` names the `www` one, and treating those as two hosts would drop every line a site has.

```yaml
pageMetrics:
  resolution:
    additionalHosts:
      - www.example.com
```

If your log carries `%v` and the metrics come out empty, this is the first thing to check: a vhost whose `ServerName` is `shop.example.com` while the site `base` says `https://example.com/` matches nothing until `shop.example.com` is listed above. The import prints how many facts each run attributed, so a run that read the log and counted none of it says so rather than reporting the same sentence as a successful one.

A log with no vhost token cannot say which host served a line, so every line counts for the site. That is the common single-vhost format and nothing about it has changed.

Lines for another host are dropped rather than recorded as unresolved URLs. The URL resolver matches across every configured site, so a foreign host that is itself a site in this installation would resolve onto that site's pages and this import would write rows for them. The site a line belongs to counts it when its own import reads the same log.

## How much it reads at once

The import emits each day as soon as the reader has moved a full day past it, rather than aggregating the whole requested window first. It therefore holds about two days at a time regardless of how far back `retentionDays` reaches, which matters because the default is 400 days and a distinct-visitor set per day and page is millions of entries over that span.

The one day of slack absorbs a log whose lines straddle midnight, or a rotated file that ends a few entries into the next day.

Files are read in the order of the first entry each one contains, not by modification time or by name. Modification times look like the obvious key and are not one: a restore from backup, an `rsync` without `-t` or a plain `cp` rewrites them all to the same instant. Names are no better, since rotation schemes disagree (`access.log.1`, `access.log-20261001.gz`) and several of them sort the wrong way round alphabetically.

If a log set still revisits a day that has already been written, the import for that provider stops with an error naming the day. That happens when one pattern matches logs from several servers, and it is deliberate: page views would be counted twice, the visit count's distinct set for the day is already gone, and the unresolved-URL store replaces rather than sums, so it would keep only the last fragment. Point the pattern at one server's rotation set.

A file that cannot be read is skipped rather than failing the run, since a glob over a rotation directory routinely picks up one the web user has no access to.

Every run still reads every file the pattern matches, since nothing records how far into a rotation set the last one got. What a run does not do is parse them: a line is rejected on the bracketed date alone unless it falls in the window being imported, which is a substring comparison rather than a regular expression, a date object and a bot classification. On a 1.44M-line archive spanning 120 days, reading and date-checking every line takes 1.7s where parsing them all takes 6.4s. Keep the pattern pointed at one site's rotation set all the same: the bytes are still read, and a nightly cron over years of archives eventually pays for them.

Where that cost sits is worth knowing if you are tempted to prune the archive. On the same 120-day set rotated the usual way, 119 gzipped files plus one live plain one, a full re-read with the date check costs 2.35s, and 2.33s of it is the gzipped files: decompression, not line scanning, is what a long archive charges for. The live log the server is still appending to is a rounding error next to it.

The bot **agent** detail (which specific crawler, see below) follows the same day boundary: the import writes out and drops each day's agent rows as the reader finishes that day, rather than holding the whole run. That is what makes a first import over the full 400 days survivable on a heavily crawled site. Measured at 400 days, 500 URLs and 10 distinct crawlers, holding the run peaks at 1945 MB against 13 MB when each day is drained as it finishes.

A run that dies partway keeps the agent detail for the days it had already finished, and discards only the day still being read. Note this is not symmetric with the metrics: the write phase starts once the whole range has been read, so a provider that fails while reading commits no metric rows at all, and can leave agent detail for days whose bot metrics were never written. The next successful run writes those metrics, and the agent rows are a diagnostic drill-down rather than a figure anything sums, which is why the memory this buys is worth it.

## What it can and cannot measure

Only the **combined** log format (Apache's and Nginx's shared default) is
understood - a custom `LogFormat`/`log_format` is not. Since a log line
carries no session or client-side signal, this provider only offers:

- **Page views**: successful (2xx) and not-modified (304) `GET` requests
  for the page. A conditional `GET` answered from the browser's own cache
  still means the page was viewed, it just needed no fresh body; excluding
  it would undercount every repeat visit on a site with normal
  cache-revalidation headers. Every other 3xx is left out, since a
  redirect's own eventual 200 on the target page is what should count.
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

All five are declared as machine metrics, so the page module folds them into a single **Bot traffic** tile that breaks itself down when opened, rather than putting five crawler counts beside the two numbers an editor came for. The two AI buckets additionally get a tile of their own beside it, and are counted in both. See [Bot traffic](../page-tiles.md#bot-traffic). The storage is untouched: each bucket keeps its own identifier and its own rows.

These are counted differently from `accesslog.pageviews` on purpose:
pageviews only count successful (2xx) and not-modified (304) `GET`
requests, but a bot hit counts regardless of HTTP status or method. A bot
hitting a dead link, a redirect, or a backend probe is exactly the traffic
these metrics exist to surface, not traffic to discard before it can be
measured. A similar (but not
identical) static-asset exclusion to pageviews' still applies, so a bot's own
CSS/JS/image requests are never counted toward any bucket - the two lists
differ slightly (for example pageviews also excludes `.pdf`/`.xml`/`.json`/
`.txt`, which the bot-side filter does not), so a path like `/robots.txt`
can still be bucketed as bot traffic even though it is excluded from
pageviews.

A bucket metric only carries a total per page per day. Which specific bot
made those hits, and whether its request path looked spoofed (a known
bot's user agent paired with a path pattern real bots do not use), is
recorded separately in the `tx_typo3pagemetrics_bot_agent_daily` table -
one row per site/page/day/bucket/agent, with a `hits_suspicious` column
for the spoofing count.

The two AI buckets read that detail back: their tiles list the crawlers
and chatbots behind the number, see
[AI chatbots and AI crawlers](../page-tiles.md#ai-chatbots-and-ai-crawlers).
The agents of the other three buckets, and the spoofing count of all five,
are not surfaced anywhere yet; today they are only queryable directly in
the database.

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
