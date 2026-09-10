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
