-- Page Metrics demo data for local development.
--
-- Imported automatically on every `ddev install` (see import_sql_data() in
-- .ddev/.setup/scripts/utils.sh), independent of the --demo flag. Pairs with
-- page_metrics_demo.xml (page tree: Home/About/Services/Blog/Contact) and
-- Tests/Acceptance/Fixtures/sites/main/config.yaml (Matomo source enabled
-- against a non-resolving placeholder endpoint, so no real API call ever
-- happens).
--
-- Values are deterministic (no RAND()), so re-running `ddev install` always
-- produces the same numbers, and dates are relative to CURDATE() so the
-- fixture never goes stale. ON DUPLICATE KEY UPDATE makes re-import
-- idempotent, matching AnnotationRepository/DailyMetricsRepository's own
-- upsert() semantics.

-- 60 days x 5 pages (Home=1, About=2, Services=3, Blog=4, Contact=5) x 5
-- Matomo metrics - covers every page in page_metrics_demo.xml's tree, so the
-- "most viewed pages" dashboard widget has more than one page to rank. 60
-- days rather than 30: the "trend" widget and the trend column on "most
-- viewed pages" compare the selected period against an equally long period
-- immediately before it, so a 30-day default period needs 60 days of
-- history to have anything but "new" to show.
--
-- The WITH RECURSIVE + CROSS JOIN SELECT is wrapped in an outer derived
-- table: MariaDB 10.6 rejects "INSERT ... WITH cte SELECT ... FROM cte
-- CROSS JOIN (...) ON DUPLICATE KEY UPDATE ..." with a syntax error right
-- before ON DUPLICATE - wrapping the whole thing as "INSERT ... SELECT *
-- FROM (WITH cte SELECT ...) sub ON DUPLICATE KEY UPDATE ..." avoids it.
INSERT INTO tx_typo3pagemetrics_daily (date, page_uid, sys_language_uid, source, metric, dimension, value)
SELECT * FROM (
    WITH RECURSIVE days AS (
        SELECT 0 AS day_offset
        UNION ALL
        SELECT day_offset + 1 FROM days WHERE day_offset < 59
    )
    SELECT
        DATE_SUB(CURDATE(), INTERVAL day_offset DAY) AS date,
        pages.page_uid,
        0 AS sys_language_uid,
        'matomo' AS source,
        metrics.metric,
        '' AS dimension,
        -- page_uid factored in with a decreasing weight (100 down to 20) so
        -- the "most viewed pages" widget ranks Home highest and Contact
        -- lowest, rather than every page landing at roughly the same total.
        -- trends.*_delta shifts pageviews, visits and entries (all applied
        -- as-is for the recent 30 days and inverted for the 30 days before
        -- that, so Home trends up, Contact trends down, and Services stays
        -- flat) - GREATEST guards the smaller metrics' bases against ever
        -- going negative. time_spent_seconds and entry_bounces are left on
        -- their own noise only, so avg_time_on_page/bounce_rate (each a
        -- ratio against visits/entries) trend the opposite way instead of
        -- landing flat: a page whose traffic is rising dilutes its average
        -- engagement and its bounce share, and vice versa - every tile ends
        -- up with a visible, page-specific trend instead of just pageviews.
        GREATEST(1, metrics.base + (100 - pages.page_uid * 20)
            + (CASE
                WHEN metrics.metric = 'matomo.pageviews' THEN (CASE WHEN day_offset < 30 THEN trends.pageviews_delta ELSE -trends.pageviews_delta END)
                WHEN metrics.metric = 'matomo.visits' THEN (CASE WHEN day_offset < 30 THEN trends.visits_delta ELSE -trends.visits_delta END)
                WHEN metrics.metric = 'matomo.entries' THEN (CASE WHEN day_offset < 30 THEN trends.entries_delta ELSE -trends.entries_delta END)
                ELSE 0
            END)
            + ((day_offset * 7 + pages.page_uid * 13) % (metrics.spread + 1))) AS value
    FROM days
    CROSS JOIN (
        SELECT 1 AS page_uid UNION ALL
        SELECT 2 UNION ALL
        SELECT 3 UNION ALL
        SELECT 4 UNION ALL
        SELECT 5
    ) pages
    INNER JOIN (
        SELECT 1 AS page_uid, 25 AS pageviews_delta, 15 AS visits_delta, 6 AS entries_delta UNION ALL
        SELECT 2, 10, 6, 3 UNION ALL
        SELECT 3, 0, 0, 0 UNION ALL
        SELECT 4, -10, -6, -3 UNION ALL
        SELECT 5, -25, -15, -6
    ) trends ON trends.page_uid = pages.page_uid
    CROSS JOIN (
        -- entries/entry_bounces bases keep bounces below entries at every
        -- offset, so the derived bounce-rate tile never exceeds 100%.
        SELECT 'matomo.pageviews' AS metric, 40 AS base, 20 AS spread UNION ALL
        SELECT 'matomo.visits', 25, 15 UNION ALL
        SELECT 'matomo.time_spent_seconds', 180, 120 UNION ALL
        SELECT 'matomo.entries', 10, 8 UNION ALL
        SELECT 'matomo.entry_bounces', 2, 3
    ) metrics
) sub
ON DUPLICATE KEY UPDATE value = VALUES(value);

-- A handful of via_redirect pageviews on Home, so the "Via redirects" stat
-- and redirect-share percentage have something to show too.
INSERT INTO tx_typo3pagemetrics_daily (date, page_uid, sys_language_uid, source, metric, dimension, value)
SELECT * FROM (
    WITH RECURSIVE days AS (
        SELECT 0 AS day_offset
        UNION ALL
        SELECT day_offset + 1 FROM days WHERE day_offset < 29
    )
    SELECT DATE_SUB(CURDATE(), INTERVAL day_offset DAY) AS date, 1 AS page_uid, 0 AS sys_language_uid, 'matomo' AS source, 'matomo.pageviews' AS metric, 'via_redirect' AS dimension, 3 + (day_offset % 4) AS value
    FROM days
) sub
ON DUPLICATE KEY UPDATE value = VALUES(value);

-- Two sample annotations (release/campaign markers) within the 30-day
-- window above, so the sparkline markers have something to render.
INSERT INTO tx_typo3pagemetrics_annotation (date, site_identifier, source, annotation_id, text)
VALUES
    (DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'main', 'matomo', 'demo-1', 'Release 2.0 deployed'),
    (DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'main', 'matomo', 'demo-2', 'Marketing campaign started')
ON DUPLICATE KEY UPDATE date = VALUES(date), text = VALUES(text);

-- Unresolved URLs, one per UnresolvedReason case, so the "not found pages"
-- dashboard widget has all four reason badges to show. __VERSION__ and
-- __SITENAME__ are substituted by import_sql_data() (see utils.sh),
-- matching the site's own base in sites/main/config.yaml - the widget's
-- query filters by "url LIKE '%://<site host>/%'", so a URL on any other
-- host (foreign-host being the deliberate exception, see below) would
-- never show up for this site.
INSERT INTO tx_typo3pagemetrics_unresolved (date, source, url_hash, url, metric, reason, value)
VALUES
    -- foreign-host is inherently never site-scoped (its whole point is a
    -- host that matches no configured site), so it can never appear in
    -- this widget - kept here anyway as realistic import-time data, e.g.
    -- for a future sitewide/unscoped variant of the widget.
    (DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'matomo', LEFT(SHA2('https://external-blog.example.com/page-metrics-launch', 256), 40), 'https://external-blog.example.com/page-metrics-launch', 'matomo.pageviews', 'foreign-host', 14),
    (DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'matomo', LEFT(SHA2('https://__VERSION__.__SITENAME__.ddev.site/downloads/brochure.pdf', 256), 40), 'https://__VERSION__.__SITENAME__.ddev.site/downloads/brochure.pdf', 'matomo.pageviews', 'file', 9),
    (DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'matomo', LEFT(SHA2('https://__VERSION__.__SITENAME__.ddev.site/old-campaign-page', 256), 40), 'https://__VERSION__.__SITENAME__.ddev.site/old-campaign-page', 'matomo.pageviews', 'non-page', 22),
    (DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'matomo', LEFT(SHA2('https://__VERSION__.__SITENAME__.ddev.site/?utm_broken=1', 256), 40), 'https://__VERSION__.__SITENAME__.ddev.site/?utm_broken=1', 'matomo.pageviews', 'unknown', 3)
ON DUPLICATE KEY UPDATE value = VALUES(value);
