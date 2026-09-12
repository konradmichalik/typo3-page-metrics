-- Page Metrics demo data for local development.
--
-- Imported automatically on every `ddev install` (see import_sql_data() in
-- .ddev/.setup/scripts/utils.sh), independent of the --demo flag. Pairs with
-- page_metrics_demo.xml (page tree: Home/About/Services/Blog/Contact) and
-- Tests/Acceptance/Fixtures/sites/main/config.yaml (Matomo provider enabled
-- against a non-resolving placeholder endpoint, so no real API call ever
-- happens).
--
-- Values are deterministic (no RAND()), so re-running `ddev install` always
-- produces the same numbers, and dates are relative to CURDATE() so the
-- fixture never goes stale. ON DUPLICATE KEY UPDATE makes re-import
-- idempotent, matching AnnotationRepository/DailyMetricsRepository's own
-- upsert() semantics.
--
-- It also seeds sys_history and, where EXT:redirects is installed,
-- sys_redirect. Those are not metrics: they are what the `typo3` annotation
-- provider derives its markers from, so the demo shows the derivation rather
-- than only its result. The derived markers are written here as well, under
-- the ids that provider generates, so a `page-metrics:import` run updates
-- them instead of adding a second set.

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
INSERT INTO tx_typo3pagemetrics_daily (date, page_uid, sys_language_uid, provider, metric, dimension, value)
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
        'matomo' AS provider,
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
        --
        -- The day-to-day noise is a sum of three sine waves at incommensurate
        -- frequencies (0.35/0.9/1.6) rather than a single "(a * day_offset +
        -- b) % spread" term: a linear ramp wrapping on a modulus draws a
        -- perfect, regularly repeating sawtooth once plotted, which reads as
        -- obviously synthetic on the chart widget - three summed sines with
        -- page_uid-dependent phase offsets settle into a smoothly wandering,
        -- non-repeating-looking curve over the 60-day window instead, still
        -- fully deterministic (no RAND()) so re-running `ddev install`
        -- reproduces the same numbers. Weights (0.25/0.15/0.10) sum to 0.5,
        -- so the whole term still stays within [0, spread] - the same range
        -- the modulo version covered.
        GREATEST(1, metrics.base + (100 - pages.page_uid * 20)
            + (CASE
                WHEN metrics.metric = 'matomo.pageviews' THEN (CASE WHEN day_offset < 30 THEN trends.pageviews_delta ELSE -trends.pageviews_delta END)
                WHEN metrics.metric = 'matomo.visits' THEN (CASE WHEN day_offset < 30 THEN trends.visits_delta ELSE -trends.visits_delta END)
                WHEN metrics.metric = 'matomo.entries' THEN (CASE WHEN day_offset < 30 THEN trends.entries_delta ELSE -trends.entries_delta END)
                ELSE 0
            END)
            + ROUND(metrics.spread * (
                0.5
                + 0.25 * SIN(day_offset * 0.35 + pages.page_uid)
                + 0.15 * SIN(day_offset * 0.9 + pages.page_uid * 2.3)
                + 0.10 * SIN(day_offset * 1.6 + pages.page_uid * 3.7)
            ))) AS value
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
INSERT INTO tx_typo3pagemetrics_daily (date, page_uid, sys_language_uid, provider, metric, dimension, value)
SELECT * FROM (
    WITH RECURSIVE days AS (
        SELECT 0 AS day_offset
        UNION ALL
        SELECT day_offset + 1 FROM days WHERE day_offset < 29
    )
    SELECT DATE_SUB(CURDATE(), INTERVAL day_offset DAY) AS date, 1 AS page_uid, 0 AS sys_language_uid, 'matomo' AS provider, 'matomo.pageviews' AS metric, 'via_redirect' AS dimension, 3 + (day_offset % 4) AS value
    FROM days
) sub
ON DUPLICATE KEY UPDATE value = VALUES(value);

-- The import state a real run over the rows above would have produced: the
-- window starts where the data starts. Without it the backend cannot tell
-- "no traffic that day" from "never imported", and a 90-day period over this
-- 60-day history would draw its first 30 days as a flat zero instead of
-- leaving them blank (see ImportCoverage).
INSERT INTO tx_typo3pagemetrics_import (provider, site_identifier, covered_from_date, covered_until_date, last_imported_date, tstamp)
VALUES
    ('matomo', 'main', DATE_SUB(CURDATE(), INTERVAL 59 DAY), CURDATE(), DATE_SUB(CURDATE(), INTERVAL 1 DAY), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE covered_from_date = VALUES(covered_from_date), covered_until_date = VALUES(covered_until_date), last_imported_date = VALUES(last_imported_date), tstamp = VALUES(tstamp);

-- Authored markers within the 30-day window above, so the sparkline markers
-- have something to render. One of each shape the chart can draw: a tick on a
-- single day, a band across the days a campaign ran, and a marker scoped to
-- one page rather than to the whole site.
INSERT INTO tx_typo3pagemetrics_annotation (date, date_until, site_identifier, provider, annotation_id, category, page_uid, include_subpages, text)
VALUES
    (DATE_SUB(CURDATE(), INTERVAL 5 DAY), NULL, 'main', 'matomo', 'demo-1', 'release', 0, 0, 'Release 2.0 deployed'),
    -- A band, not a tick: a campaign that ran for a fortnight drawn as a line
    -- on the Monday it started would say it was over by the evening.
    (DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'main', 'matomo', 'demo-2', 'campaign', 0, 0, 'Spring campaign'),
    -- Scoped to Services (uid 3) and its subtree, so the page module shows it
    -- there and on nothing else, which is the whole point of page scope.
    (DATE_SUB(CURDATE(), INTERVAL 11 DAY), NULL, 'main', 'matomo', 'demo-3', 'redesign', 3, 1, 'Services section relaunched')
ON DUPLICATE KEY UPDATE date = VALUES(date), date_until = VALUES(date_until), category = VALUES(category), page_uid = VALUES(page_uid), include_subpages = VALUES(include_subpages), text = VALUES(text);

-- Editorial history, so the markers the `typo3` annotation provider derives
-- from the installation itself have something to derive from. These are the
-- source rows, not the markers: running `page-metrics:import` folds them into
-- one marker per page and day and writes those below. Seeded here as well as
-- derived so a fresh `ddev install` shows them without an import run, and the
-- annotation ids match exactly what the provider generates, so a later real
-- import updates these rows instead of adding a second set.
--
-- Relative to CURDATE() like everything else here: the provider only looks
-- back 90 days, so a fixture with absolute timestamps would stop producing
-- markers a few months after it was written.
--
-- actiontype 2 is "record modified" (see TYPO3\CMS\Core\DataHandling\History\
-- RecordHistoryStore::ACTION_MODIFY). userid is set because a real row has
-- one; the provider never selects that column, which is the point.
-- sys_history has no unique key to upsert against, so re-running this file
-- would stack a second set of rows. The correlation id is what marks them as
-- this fixture's own.
DELETE FROM sys_history WHERE correlation_id LIKE 'demo-history-%';

INSERT INTO sys_history (tstamp, actiontype, usertype, userid, recuid, tablename, history_data, workspace, correlation_id)
VALUES
    -- Three edits to About on one day. The marker says "3 changes", not three
    -- markers.
    (UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 4 DAY)) + 32400, 2, 'BE', 1, 2, 'pages', '{}', 0, 'demo-history-1'),
    (UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 4 DAY)) + 36000, 2, 'BE', 1, 2, 'pages', '{}', 0, 'demo-history-2'),
    (UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 4 DAY)) + 50400, 2, 'BE', 1, 2, 'pages', '{}', 0, 'demo-history-3'),
    -- A single edit to Blog on another day, so both wordings appear.
    (UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 9 DAY)) + 39600, 2, 'BE', 1, 4, 'pages', '{}', 0, 'demo-history-4'),
    -- A content element rather than the page record. It sits on Home (uid 1),
    -- and the marker lands on Home, because sys_history stores the element's
    -- own uid and the page it belongs to has to be joined back in.
    (UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 2 DAY)) + 43200, 2, 'BE', 1, 1, 'tt_content', '{}', 0, 'demo-history-5')
ON DUPLICATE KEY UPDATE tstamp = VALUES(tstamp);

-- A redirect pointing at Services, for the seo marker. EXT:redirects is
-- optional, so this runs only where its table exists: without the guard the
-- whole fixture import would abort on an installation that does not have it,
-- which is the default one.
SET @has_redirects = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sys_redirect');

-- Two statements, because PREPARE takes one each. The delete keeps re-running
-- this file from stacking a second redirect.
SET @redirect_delete = IF(@has_redirects > 0, 'DELETE FROM sys_redirect WHERE source_path = ''/old-services''', 'DO 0');
PREPARE demo_redirect_delete FROM @redirect_delete;
EXECUTE demo_redirect_delete;
DEALLOCATE PREPARE demo_redirect_delete;

SET @redirect_insert = IF(
    @has_redirects > 0,
    'INSERT INTO sys_redirect (pid, createdon, updatedon, deleted, disabled, source_host, source_path, target, target_statuscode) VALUES (0, UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 7 DAY)) + 45000, UNIX_TIMESTAMP(), 0, 0, ''*'', ''/old-services'', ''t3://page?uid=3'', 307)',
    'DO 0'
);
PREPARE demo_redirect_insert FROM @redirect_insert;
EXECUTE demo_redirect_insert;
DEALLOCATE PREPARE demo_redirect_insert;

-- What the `typo3` provider derives from the rows above. The ids follow its
-- own format (<category>-<page uid>-<Ymd>), so `page-metrics:import` upserts
-- these same rows rather than duplicating them.
INSERT INTO tx_typo3pagemetrics_annotation (date, date_until, site_identifier, provider, annotation_id, category, page_uid, include_subpages, text)
VALUES
    (DATE_SUB(CURDATE(), INTERVAL 4 DAY), NULL, 'main', 'typo3', CONCAT('content-2-', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 4 DAY), '%Y%m%d')), 'content', 2, 0, 'Page updated, 3 changes'),
    (DATE_SUB(CURDATE(), INTERVAL 9 DAY), NULL, 'main', 'typo3', CONCAT('content-4-', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 9 DAY), '%Y%m%d')), 'content', 4, 0, 'Page updated'),
    (DATE_SUB(CURDATE(), INTERVAL 2 DAY), NULL, 'main', 'typo3', CONCAT('content-1-', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '%Y%m%d')), 'content', 1, 0, 'Page updated'),
    (DATE_SUB(CURDATE(), INTERVAL 7 DAY), NULL, 'main', 'typo3', CONCAT('seo-3-', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 7 DAY), '%Y%m%d')), 'seo', 3, 0, 'Redirect created')
ON DUPLICATE KEY UPDATE date = VALUES(date), category = VALUES(category), page_uid = VALUES(page_uid), text = VALUES(text);

-- Unresolved URLs, one per UnresolvedReason case, so the "not found pages"
-- dashboard widget has all four reason badges to show. __VERSION__ and
-- __SITENAME__ are substituted by import_sql_data() (see utils.sh),
-- matching the site's own base in sites/main/config.yaml - the widget's
-- query filters by "url LIKE '%://<site host>/%'", so a URL on any other
-- host (foreign-host being the deliberate exception, see below) would
-- never show up for this site.
INSERT INTO tx_typo3pagemetrics_unresolved (date, provider, url_hash, url, metric, reason, value)
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
