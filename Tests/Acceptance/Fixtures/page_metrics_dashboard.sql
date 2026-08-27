-- Seeds a ready-made "Page Metrics" dashboard for the demo admin user
-- (uid 1, created by install:setup/setup before this fixture runs), so
-- `ddev install` lands on a working dashboard instead of an empty one the
-- user has to build via the wizard.
--
-- The widgets column mirrors the 'pageMetrics' preset's defaultWidgets in
-- Configuration/Backend/DashboardPresets.php - kept in sync by hand, since
-- this INSERT can't read that PHP array at import time. Update both
-- together.
--
-- be_dashboards.identifier only has a plain KEY, not a UNIQUE one (see
-- vendor/typo3/cms-dashboard/ext_tables.sql), so a plain INSERT would add a
-- duplicate row on every re-run of `ddev install`. The NOT EXISTS guard
-- makes this idempotent instead.
INSERT INTO be_dashboards (pid, tstamp, crdate, deleted, hidden, starttime, endtime, cruser_id, identifier, title, widgets)
SELECT 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 0, 0, 0, 1, 'page-metrics-demo', 'Page Metrics', '{"widget_1":{"identifier":"pageMetrics"},"widget_2":{"identifier":"pageMetricsChart"},"widget_3":{"identifier":"pageMetricsChartCompact","settings":{"metric":"matomo.visits"}},"widget_4":{"identifier":"pageMetricsChartCompact","settings":{"metric":"matomo.bounce_rate"}},"widget_5":{"identifier":"pageMetricsTrend","settings":{"direction":"up"}},"widget_6":{"identifier":"pageMetricsTrend","settings":{"direction":"down"}},"widget_7":{"identifier":"pageMetricsTopPages"},"widget_8":{"identifier":"pageMetricsNotFoundPages"},"widget_9":{"identifier":"pageMetricsAnnotations"}}'
WHERE NOT EXISTS (SELECT 1 FROM be_dashboards WHERE identifier = 'page-metrics-demo');
