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

-- 30 days x 2 pages (Home=1, About=2) x 5 Matomo metrics.
--
-- The WITH RECURSIVE + CROSS JOIN SELECT is wrapped in an outer derived
-- table: MariaDB 10.6 rejects "INSERT ... WITH cte SELECT ... FROM cte
-- CROSS JOIN (...) ON DUPLICATE KEY UPDATE ..." with a syntax error right
-- before ON DUPLICATE - wrapping the whole thing as "INSERT ... SELECT *
-- FROM (WITH cte SELECT ...) sub ON DUPLICATE KEY UPDATE ..." avoids it.
INSERT INTO tx_pagemetrics_daily (date, page_uid, sys_language_uid, source, metric, dimension, value)
SELECT * FROM (
    WITH RECURSIVE days AS (
        SELECT 0 AS day_offset
        UNION ALL
        SELECT day_offset + 1 FROM days WHERE day_offset < 29
    )
    SELECT
        DATE_SUB(CURDATE(), INTERVAL day_offset DAY) AS date,
        pages.page_uid,
        0 AS sys_language_uid,
        'matomo' AS source,
        metrics.metric,
        '' AS dimension,
        metrics.base + ((day_offset * 7 + pages.page_uid * 13) % (metrics.spread + 1)) AS value
    FROM days
    CROSS JOIN (
        SELECT 1 AS page_uid UNION ALL
        SELECT 2
    ) pages
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
INSERT INTO tx_pagemetrics_daily (date, page_uid, sys_language_uid, source, metric, dimension, value)
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
INSERT INTO tx_pagemetrics_annotation (date, site_identifier, source, annotation_id, text)
VALUES
    (DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'main', 'matomo', 'demo-1', 'Release 2.0 deployed'),
    (DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'main', 'matomo', 'demo-2', 'Marketing campaign started')
ON DUPLICATE KEY UPDATE date = VALUES(date), text = VALUES(text);
